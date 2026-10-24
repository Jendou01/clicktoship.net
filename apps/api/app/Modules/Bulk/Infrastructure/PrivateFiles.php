<?php

namespace App\Modules\Bulk\Infrastructure;

use App\Modules\Bulk\Domain\ImportFailure;

/** Authenticated streaming encryption, never a public storage disk or client-provided path. */
final class PrivateFiles
{
    private function key(): string
    {
        return hash('sha256', 'bulk-file:'.config('app.key'), true);
    }

    public function root(): string
    {
        $dir = storage_path('app/private/bulk');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public function store(string $source, string $id): string
    {
        $target = $this->root().'/'.$id.'.enc';
        $in = fopen($source, 'rb');
        $out = fopen($target, 'xb');
        chmod($target, 0600);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
        fwrite($out, $header);
        try {
            while (! feof($in)) {
                $plain = fread($in, 65536);
                if ($plain === false) {
                    throw new ImportFailure('FILE_READ');
                }
                if ($plain === '') {
                    break;
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                fwrite($out, pack('N', strlen($cipher)).$cipher);
            }
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            fwrite($out, pack('N', strlen($cipher)).$cipher);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $id.'.enc';
    }

    public function withPlain(object $file, callable $read): mixed
    {
        if (! preg_match('/^[0-9a-f-]{36}\.enc$/D', $file->storage_key)) {
            throw new ImportFailure('FILE_KEY');
        }
        $source = $this->root().'/'.$file->storage_key;
        if (! is_file($source) || is_link($source)) {
            throw new ImportFailure('FILE_MISSING');
        }
        $temp = tempnam($this->root(), 'scratch-');
        chmod($temp, 0600);
        $in = fopen($source, 'rb');
        $out = fopen($temp, 'wb');
        $total = 0;
        $final = false;
        try {
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new ImportFailure('FILE_TAMPERED');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
            while (! feof($in)) {
                $length = fread($in, 4);
                if ($length === '') {
                    break;
                }
                if (strlen($length) !== 4 || $final) {
                    throw new ImportFailure('FILE_TAMPERED');
                }
                $size = unpack('N', $length)[1];
                if ($size < 17 || $size > 65553) {
                    throw new ImportFailure('FILE_TAMPERED');
                }
                $cipher = fread($in, $size);
                if (strlen($cipher) !== $size || ! ($decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher))) {
                    throw new ImportFailure('FILE_TAMPERED');
                }
                [$plain, $tag] = $decoded;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $total += strlen($plain);
                if ($total > config('bulk.max_bytes')) {
                    throw new ImportFailure('FILE_LIMIT');
                }
                fwrite($out, $plain);
            }
            fclose($out);
            $out = null;
            if (! $final || $total !== $file->size_bytes || ! hash_equals($file->sha256, hash_file('sha256', $temp))) {
                throw new ImportFailure('FILE_TAMPERED');
            }

            return $read($temp);
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            } @unlink($temp);
        }
    }
}
