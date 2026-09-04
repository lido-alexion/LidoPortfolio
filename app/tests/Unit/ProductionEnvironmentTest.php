<?php

namespace Tests\Unit;

use App\Support\ProductionEnvironment;
use PHPUnit\Framework\TestCase;

class ProductionEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/lido-env-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/home/user/public_html/portfolio/laravel/config', 0777, true);
        mkdir($this->root.'/home/user/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_external_home_config_wins_over_web_tree_copy(): void
    {
        $base = $this->root.'/home/user/public_html/portfolio/laravel';
        $external = $this->root.'/home/user/config/'.ProductionEnvironment::FILE_NAME;
        $webTree = $base.'/config/'.ProductionEnvironment::FILE_NAME;
        file_put_contents($external, "APP_KEY=external\n");
        file_put_contents($webTree, "APP_KEY=unsafe\n");

        $this->assertSame(realpath($external), ProductionEnvironment::resolve($base));
    }

    public function test_explicit_readable_path_has_priority(): void
    {
        $base = $this->root.'/home/user/public_html/portfolio/laravel';
        $external = $this->root.'/home/user/config/'.ProductionEnvironment::FILE_NAME;
        $explicit = $this->root.'/explicit.env';
        file_put_contents($external, "APP_KEY=external\n");
        file_put_contents($explicit, "APP_KEY=explicit\n");

        $this->assertSame(realpath($explicit), ProductionEnvironment::resolve($base, $explicit));
    }

    public function test_missing_external_file_returns_null(): void
    {
        $base = $this->root.'/home/user/public_html/portfolio/laravel';

        $this->assertNull(ProductionEnvironment::resolve($base));
    }
}
