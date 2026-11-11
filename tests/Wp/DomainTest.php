<?php

declare(strict_types=1);

namespace WPBrewer\Updater\Tests\Wp;

use PHPUnit\Framework\TestCase;
use WPBrewer\Updater\Domain;

/**
 * @license GPL-3.0-or-later
 */
final class DomainTest extends TestCase
{
    public function test_contract_examples(): void
    {
        $this->assertSame('example.com', Domain::normalize('HTTPS://WWW.Example.com:443/shop/?q=1'));
        $this->assertSame('example.com', Domain::normalize('www.example.com.'));
        $this->assertSame('shop.example.com', Domain::normalize('shop.example.com'));
        $this->assertSame('example.com', Domain::normalize('https://user:pass@www.example.com/a'));
    }

    public function test_strips_one_www_label_and_keeps_www2(): void
    {
        $this->assertSame('shop.example.com', Domain::normalize('www.shop.example.com'));
        $this->assertSame('www2.example.com', Domain::normalize('www2.example.com'));
    }

    public function test_keeps_an_ip_without_port_or_brackets(): void
    {
        $this->assertSame('127.0.0.1', Domain::normalize('http://127.0.0.1:8090/'));
        $this->assertSame('::1', Domain::normalize('https://[::1]/wp-admin/'));
    }

    public function test_idna_and_rejection(): void
    {
        $this->assertSame('', Domain::normalize('   '));
        $this->assertSame('', Domain::normalize('http://'));
        if (function_exists('idn_to_ascii')) {
            $this->assertSame('xn--mnchen-3ya.de', Domain::normalize('https://www.münchen.de/shop'));
        }
        $this->assertSame('', Domain::normalize(str_repeat('a', 254) . '.com'));
    }
}
