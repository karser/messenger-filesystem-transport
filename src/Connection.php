<?php

namespace Pnz\Messenger\FilesystemTransport;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\LockInterface;

class Connection
{
    private const QUEUE_INDEX_FILENAME = 'queue.index';
    private const QUEUE_DATA_FILENAME = 'queue.data';
    private const LONG_BYTE_LENGTH = 8;

    /**
     * @var string
     */
    private $path;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var LockInterface
     */
    private $lock;

    /**
     * @var array[]
     */
    private $options;

    public function __construct(string $path, Filesystem $filesystem, LockInterface $lock, array $options)
    {
        $this->path = $path;
        $this->filesystem = $filesystem;
        $this->lock = $lock;
        $this->options = [
            'compress' => filter_var($options['compress'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'loop_sleep'  => filter_var($options['loop_sleep'] ?? 500000, FILTER_VALIDATE_INT),
        ];
    }

    public static function fromDsn(string $dsn, array $options = [], Filesystem $filesystem, Factory $lockFactory): self
    {
        if (false === $parsedUrl = parse_url($dsn)) {
            throw new \InvalidArgumentException(sprintf('The given Filesystem DSN "%s" is invalid.', $dsn));
        }

        $host = $parsedUrl['host'] ?? null;
        $path = $parsedUrl['path'] ?? null;

        if (!$host || !$path) {
            throw new \InvalidArgumentException(sprintf('The given Filesystem DSN "%s" is invalid: path missing.', $dsn));
        }

        // Rebuild the full path, as the host is now part of the path
        $fullPath = DIRECTORY_SEPARATOR.$host.($path ? DIRECTORY_SEPARATOR.$path : null);

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $parsedQuery);

            $options = array_replace_recursive($options, $parsedQuery);
        }

        return new self($fullPath, $filesystem, $lockFactory->createLock($path), $options);
    }

    public function publish(string $body, array $headers = array()): void
    {
        $this->lock->acquire(true);
        if ($this->shouldSetup()) {
            $this->setup();
        }

        $block = new FileQueueBlock($body, $headers);

        // Write the block to the data file
        $dataFile = fopen($this->getQueueFiles()[self::QUEUE_DATA_FILENAME], 'ab+');
        if (!$dataFile) {
            $this->lock->release();

            throw new \RuntimeException(sprintf(
                'Filesystem queue: unable to open data-file %s',
                $this->getQueueFiles()[self::QUEUE_DATA_FILENAME]
            ));
        }

        $data = serialize($block);
        $data = $this->options['compress'] ? gzdeflate($data) : $data;
        fwrite($dataFile, $data);
        fclose($dataFile);

        // The index file contains the list of block sizes with a fixed-length structure
        // This allows a fast fetching of blocks with a direct seek on the data-file
        $indexFile = fopen($this->getQueueFiles()[self::QUEUE_INDEX_FILENAME], 'ab+');

        if (!$dataFile) {
            $this->lock->release();

            throw new \RuntimeException(sprintf(
                'Filesystem queue: unable to open index-file %s. Critical: the queue files are not in sync anymore!',
                $this->getQueueFiles()[self::QUEUE_DATA_FILENAME]
            ));
        }

        // The 'J': unsigned long long (always 64 bit, big endian byte order)
        fwrite($indexFile, pack('J', \strlen($data)));
        fclose($indexFile);

        $this->lock->release();
    }

    public function get(): ?FileQueueBlock
    {
        $this->lock->acquire(true);
        if ($this->shouldSetup()) {
            $this->setup();
        }

        $indexFile = fopen($this->getQueueFiles()[self::QUEUE_INDEX_FILENAME], 'cb+');
        if (!$indexFile) {
            $this->lock->release();

            throw new \RuntimeException(sprintf(
                'Filesystem queue: unable to open index-file %s',
                $this->getQueueFiles()[self::QUEUE_DATA_FILENAME]
            ));
        }


        $indexFileSize = (int) fstat($indexFile)['size'];

        // If the index file is empty, there's nothing to do.
        if (!$indexFileSize) {
            fclose($indexFile);
            $this->lock->release();
            return null;
        }

        fseek($indexFile, -1 * self::LONG_BYTE_LENGTH, SEEK_END);
        $size = current(unpack('J', fread($indexFile, self::LONG_BYTE_LENGTH)));
        ftruncate($indexFile, $indexFileSize - self::LONG_BYTE_LENGTH);
        fclose($indexFile);

        $dataFile = fopen($this->getQueueFiles()[self::QUEUE_DATA_FILENAME], 'cb+');
        if (!$dataFile) {
            $this->lock->release();

            throw new \RuntimeException(sprintf(
                'Filesystem queue: unable to open data-file %s. Critical: the data files are not in sync anymore!',
                $this->getQueueFiles()[self::QUEUE_DATA_FILENAME]
            ));
        }


        fseek($dataFile, -1 * $size, SEEK_END);
        $data = fread($dataFile, $size);
        $dataFileSize = (int) fstat($dataFile)['size'];

        ftruncate($dataFile, $dataFileSize - $size);
        fclose($dataFile);
        $this->lock->release();

        $block = unserialize(
            $this->options['compress'] ? gzinflate($data) : $data,
            [FileQueueBlock::class]
        );

        return $block;
    }

    protected function shouldSetup(): bool
    {
        return !$this->filesystem->exists($this->getQueueFiles());
    }

    public function setup(): void
    {
        $this->filesystem->mkdir($this->path);
        $this->filesystem->touch($this->getQueueFiles());
    }

    private function getQueueFiles(): array
    {
      return [
          self::QUEUE_DATA_FILENAME => $this->path.DIRECTORY_SEPARATOR.self::QUEUE_DATA_FILENAME,
          self::QUEUE_INDEX_FILENAME => $this->path.DIRECTORY_SEPARATOR.self::QUEUE_INDEX_FILENAME,
      ];
    }

    public function getConnectionOptions(): array
    {
        return $this->options;
    }
}