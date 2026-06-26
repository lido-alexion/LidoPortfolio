<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesPortfolioProfiles;

abstract class TestCase extends BaseTestCase
{
    use CreatesPortfolioProfiles;
}
