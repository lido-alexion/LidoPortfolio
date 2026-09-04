<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SingleFolderDeploymentContractTest extends TestCase
{
    public function test_front_controller_uses_nested_laravel_and_one_public_path(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/deploy/portfolio-single-folder-index.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("__DIR__.'/laravel'", $source);
        $this->assertStringContainsString('usePublicPath(__DIR__)', $source);
        $this->assertStringNotContainsString('../lidoportfolio', $source);
    }

    public function test_parent_and_nested_web_rules_deny_laravel_tree(): void
    {
        $parent = file_get_contents(dirname(__DIR__, 3).'/deploy/portfolio-single-folder.htaccess');
        $nested = file_get_contents(dirname(__DIR__, 3).'/deploy/public_html-lidoportfolio-.htaccess');

        $this->assertIsString($parent);
        $this->assertIsString($nested);
        $this->assertMatchesRegularExpression('/RewriteRule \^laravel.*\[F,L,NC\]/', $parent);
        $this->assertStringContainsString('Deny from all', $nested);
    }

    public function test_packager_has_explicit_secret_and_duplicate_build_guards(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/deploy/prepare-single-folder-upload.ps1');

        $this->assertIsString($source);
        $this->assertStringContainsString('staging-single-folder', $source);
        $this->assertStringContainsString("Join-Path \$laravelRoot '.env'", $source);
        $this->assertStringContainsString("Join-Path \$laravelRoot 'public/build'", $source);
        $this->assertStringContainsString("Join-Path \$laravelRoot 'config/DBConfig.php'", $source);
    }
}
