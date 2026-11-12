<?php

namespace {
    if (! class_exists('PclZip')) {
        /**
         * Records calls instead of writing a zip: BackupArchiverPclZipTest
         * checks how the fallback drives PclZip, not PclZip itself.
         */
        class PclZip
        {
            /** @var list<array{method: string, args: array<int, mixed>}> */
            public static array $calls = [];

            public function __construct(public string $zipname) {}

            public function create(mixed ...$args): array
            {
                self::$calls[] = ['method' => 'create', 'args' => $args];

                return [['status' => 'ok']];
            }

            public function add(mixed ...$args): array
            {
                self::$calls[] = ['method' => 'add', 'args' => $args];

                return [['status' => 'ok']];
            }

            public function errorInfo(bool $full = false): string
            {
                return '';
            }
        }
    }
    defined('PCLZIP_ATT_FILE_NAME') || define('PCLZIP_ATT_FILE_NAME', 79001);
    defined('PCLZIP_ATT_FILE_NEW_FULL_NAME') || define('PCLZIP_ATT_FILE_NEW_FULL_NAME', 79003);
}

namespace ClockworkCompanion\Tests {

    use ClockworkCompanion\Backup\BackupArchiver;
    use ClockworkCompanion\Backup\Paths;
    use PHPUnit\Framework\TestCase;

    class BackupArchiverPclZipTest extends TestCase
    {
        private string $tempDir;

        protected function setUp(): void
        {
            parent::setUp();
            \PclZip::$calls = [];
            $this->tempDir = sys_get_temp_dir().'/cwk_pclzip_'.bin2hex(random_bytes(4));
            @mkdir($this->tempDir, 0755, true);
            @touch(ABSPATH.'wp-admin/includes/class-pclzip.php');
        }

        protected function tearDown(): void
        {
            Paths::rmrf($this->tempDir);
            parent::tearDown();
        }

        /**
         * add() once per file rewrote the archive on every call; one
         * create() writes it once. Unreadable files are still skipped.
         */
        public function testFallbackWritesTheArchiveInOneCreateCallAndSkipsMissingFiles(): void
        {
            file_put_contents($this->tempDir.'/a.txt', 'a');
            file_put_contents($this->tempDir.'/b.txt', 'b');
            $entries = [
                ['source' => $this->tempDir.'/a.txt', 'dest' => 'wp-content/a.txt'],
                ['source' => $this->tempDir.'/gone.txt', 'dest' => 'wp-content/gone.txt'],
                ['source' => $this->tempDir.'/b.txt', 'dest' => 'wp-content/sub/b.txt'],
            ];

            $method = new \ReflectionMethod(BackupArchiver::class, 'writeWithPclZip');
            $result = $method->invoke(new BackupArchiver(), $this->tempDir.'/out.zip', $entries);

            $this->assertNull($result);
            $this->assertCount(1, \PclZip::$calls);
            $this->assertSame('create', \PclZip::$calls[0]['method']);
            $this->assertSame([
                [PCLZIP_ATT_FILE_NAME => $this->tempDir.'/a.txt', PCLZIP_ATT_FILE_NEW_FULL_NAME => 'wp-content/a.txt'],
                [PCLZIP_ATT_FILE_NAME => $this->tempDir.'/b.txt', PCLZIP_ATT_FILE_NEW_FULL_NAME => 'wp-content/sub/b.txt'],
            ], \PclZip::$calls[0]['args'][0]);
        }

        public function testZipArchiveWritesEveryFileAndOneManifest(): void
        {
            $entries = [];
            for ($i = 0; $i < 650; $i++) {
                file_put_contents($this->tempDir."/f{$i}.txt", (string) $i);
                $entries[] = ['source' => $this->tempDir."/f{$i}.txt", 'dest' => "wp-content/f{$i}.txt"];
            }

            $method = new \ReflectionMethod(BackupArchiver::class, 'writeWithZipArchive');
            $result = $method->invoke(new BackupArchiver(), $this->tempDir.'/out.zip', $entries, ['version' => '1.0']);

            $this->assertNull($result);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($this->tempDir.'/out.zip') === true);
            $this->assertSame(651, $zip->numFiles);
            $this->assertSame('{"version":"1.0"}', str_replace([" ", "\n"], '', (string) $zip->getFromName('manifest.json')));
            $zip->close();
        }
    }
}
