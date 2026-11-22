<?php

declare(strict_types=1);

use Neverball\AddonTool;
use PHPUnit\Framework\TestCase;

final class AddonToolTest extends TestCase
{
    private static string $baseDir;
    private static string $tempStorageDir;

    public static function setUpBeforeClass(): void
    {
        self::$baseDir = dirname(__DIR__);
        self::$tempStorageDir = sys_get_temp_dir() . '/neverball-addon-test-' . getmypid();

        @mkdir(self::$tempStorageDir . '/uploads', 0755, true);
        @mkdir(self::$tempStorageDir . '/hashes', 0755, true);
        @mkdir(self::$tempStorageDir . '/tokens', 0755, true);
        @mkdir(self::$tempStorageDir . '/logs', 0755, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (!is_dir(self::$tempStorageDir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$tempStorageDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir(self::$tempStorageDir);
    }

    public function testExtractZipAndStoreZipWithSpacesAndApostrophesInSetFilename(): void
    {
        $zipPath = $this->createTestZip("set-my's level set.txt", "My's Level Set\nA test set\n");

        try {
            $subScript = sprintf(
                'define("IN_APP", true); define("BASE_DIR", %s); define("STORAGE_DIR", %s); define("LOG_DIR", %s); define("BASE_URL", "https://example.test"); require BASE_DIR . "/vendor/autoload.php"; require BASE_DIR . "/src/AddonTool.php"; $t = new Neverball\AddonTool(); $em = new ReflectionMethod(Neverball\AddonTool::class, "extractZip"); $em->setAccessible(true); $sm = new ReflectionMethod(Neverball\AddonTool::class, "storeZip"); $sm->setAccessible(true); $ext = $em->invoke($t, %s); $path = $sm->invoke($t, %s, $ext["id"]); echo json_encode(["extracted" => $ext, "stored" => basename($path), "exists" => file_exists($path)]);',
                var_export(self::$baseDir, true),
                var_export(self::$tempStorageDir, true),
                var_export(self::$tempStorageDir . '/logs', true),
                var_export($zipPath, true),
                var_export($zipPath, true)
            );

            $out = shell_exec('php -r ' . escapeshellarg($subScript));
            $json = json_decode((string) $out, true);

            $this->assertIsArray($json);
            $this->assertSame("set-my's level set", $json['extracted']['id'] ?? null);
            $this->assertSame("My's Level Set", $json['extracted']['addonName'] ?? null);
            $this->assertTrue((bool) ($json['exists'] ?? false));
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+-[0-9a-f]{32}\.zip$/', (string) ($json['stored'] ?? ''));
        } finally {
            @unlink($zipPath);
        }
    }

    public function testSourceUsesCsrfTokenInsteadOfFormLoadedAt(): void
    {
        $source = file_get_contents(self::$baseDir . '/src/AddonTool.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('form_loaded_at', $source);
        $this->assertStringContainsString('csrf_token', $source);
    }

    public function testHttpCsrfAndSpacesInSetFilename(): void
    {
        $port = 18923;
        $baseUrl = "http://127.0.0.1:$port";
        $docRoot = self::$baseDir . '/public';
        $cmd = sprintf('BASE_URL=%s php -d variables_order=EGPCS -S 127.0.0.1:%d -t %s', escapeshellarg($baseUrl), $port, escapeshellarg($docRoot));
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$baseDir);
        $this->assertIsResource($proc);

        usleep(300000);

        $zipPath = $this->createTestZip("set-my's level set.txt");
        $uniqueZipPath = $this->createTestZip("set-my's level set.txt", "My's Level Set\nTest " . bin2hex(random_bytes(8)) . "\n");
        $uniqueHash = hash_file('sha256', $uniqueZipPath);
        $beforeUploads = glob(self::$baseDir . '/storage/uploads/set-my_s_level_set-*.zip') ?: [];
        $beforeTokens  = glob(self::$baseDir . '/storage/tokens/*') ?: [];

        try {
            $getRes = $this->httpRequest($baseUrl . '/');
            $this->assertSame(200, $getRes['code']);
            $this->assertEmpty($getRes['headers']['set-cookie'] ?? []);

            $csrfToken = '';
            if (preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $getRes['body'], $m)) {
                $csrfToken = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            $this->assertNotSame('', $csrfToken);
            $this->assertCount(3, explode('.', $csrfToken));

            // Embed mode renders dark transparent layout and resize postMessage script
            $embedRes = $this->httpRequest($baseUrl . '/?embed=1');
            $this->assertSame(200, $embedRes['code']);
            $this->assertStringContainsString('bg-transparent', $embedRes['body']);
            $this->assertStringContainsString('neverball-addon-resize', $embedRes['body']);

            $originHeader = ['Origin: ' . $baseUrl];

            // Missing CSRF token should fail explicitly
            $noCsrfRes = $this->httpRequest($baseUrl . '/', 'POST', [
                'website' => '',
                'name'    => 'Tester',
                'email'   => 'tester@example.com',
                'message' => 'Test message',
                'zip'     => new CURLFile($zipPath, 'application/zip', 'test.zip'),
            ], $originHeader);
            $noCsrfJson = json_decode($noCsrfRes['body'], true);
            $this->assertFalse($noCsrfJson['success'] ?? null);

            // Invalid CSRF token should fail explicitly
            $badCsrfRes = $this->httpRequest($baseUrl . '/', 'POST', [
                'website'    => '',
                'csrf_token' => 'invalid-token',
                'name'       => 'Tester',
                'email'      => 'tester@example.com',
                'message'    => 'Test message',
                'zip'        => new CURLFile($zipPath, 'application/zip', 'test.zip'),
            ], $originHeader);
            $badCsrfJson = json_decode($badCsrfRes['body'], true);
            $this->assertFalse($badCsrfJson['success'] ?? null);

            // Mismatched Origin should fail explicitly
            $badOriginRes = $this->httpRequest($baseUrl . '/', 'POST', [
                'website'    => '',
                'csrf_token' => $csrfToken,
                'name'       => 'Tester',
                'email'      => 'tester@example.com',
                'message'    => 'Test message',
                'zip'        => new CURLFile($zipPath, 'application/zip', 'test.zip'),
            ], ['Origin: https://evil.example']);
            $badOriginJson = json_decode($badOriginRes['body'], true);
            $this->assertFalse($badOriginJson['success'] ?? null);
            $this->assertStringContainsString('session token', (string) ($badOriginJson['error'] ?? ''));

            // Missing Origin and Referer should fail explicitly
            $noOriginRes = $this->httpRequest($baseUrl . '/', 'POST', [
                'website'    => '',
                'csrf_token' => $csrfToken,
                'name'       => 'Tester',
                'email'      => 'tester@example.com',
                'message'    => 'Test message',
                'zip'        => new CURLFile($zipPath, 'application/zip', 'test.zip'),
            ]);
            $noOriginJson = json_decode($noOriginRes['body'], true);
            $this->assertFalse($noOriginJson['success'] ?? null);

            // Valid CSRF token + matching Origin submitted without cookies
            $validRes = $this->httpRequest($baseUrl . '/', 'POST', [
                'website'    => '',
                'csrf_token' => $csrfToken,
                'name'       => 'Tester',
                'email'      => 'tester@example.com',
                'message'    => 'Test message',
                'zip'        => new CURLFile($uniqueZipPath, 'application/zip', 'test.zip'),
            ], $originHeader);
            $validJson = json_decode($validRes['body'], true);
            $errMsg = (string) ($validJson['error'] ?? '');

            $this->assertStringNotContainsString('no set-*.txt file found', $errMsg);
            $this->assertStringNotContainsString('session token', $errMsg);
            if (($validJson['success'] ?? null) === true) {
                $this->assertNotEmpty($validJson['pr_url'] ?? '');
            }
        } finally {
            @unlink($zipPath);
            @unlink($uniqueZipPath);
            @unlink(self::$baseDir . '/storage/hashes/' . $uniqueHash);
            foreach (array_diff(glob(self::$baseDir . '/storage/uploads/set-my_s_level_set-*.zip') ?: [], $beforeUploads) as $f) {
                @unlink($f);
            }
            foreach (array_diff(glob(self::$baseDir . '/storage/tokens/*') ?: [], $beforeTokens) as $f) {
                @unlink($f);
            }
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    private function createTestZip(string $setFilename, string $setContent = "My's Level Set\nDescription\n"): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nbzip_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($setFilename, $setContent);
        $zip->addFromString("map-my's set/level 1.sol", 'dummy');
        $zip->addFromString("map-my's set/level 1.map", 'dummy');
        $zip->close();
        return $tmp;
    }

    private function httpRequest(string $url, string $method = 'GET', array $postFields = [], array $requestHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = [];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
            return $len;
        });
        if ($requestHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'headers' => $headers, 'body' => (string) $body];
    }
}
