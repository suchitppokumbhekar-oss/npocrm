<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DeviceLeadImportController extends Controller
{
    public function share(Request $request)
    {
        $title = trim((string) $request->input('title', ''));
        $text  = trim((string) $request->input('text', ''));
        $url   = trim((string) $request->input('url', ''));
        $name = ''; $phone = ''; $email = '';

        $files = $request->file('files');
        $files = is_array($files) ? $files : ($files ? [$files] : []);
        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) continue;
            $mime = strtolower((string) $file->getMimeType());
            $ext  = strtolower((string) $file->getClientOriginalExtension());
            if (str_contains($mime, 'vcard') || in_array($ext, ['vcf', 'vcard'], true)) {
                $vcard = @file_get_contents($file->getRealPath()) ?: '';
                [$name, $phone, $email] = $this->parseVcard($vcard);
                break;
            }
        }

        $combined = trim(implode("\n", array_filter([$title, $text, $url])));
        if ($phone === '' && preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $combined, $m)) {
            $phone = trim($m[0]);
        }
        if ($name === '' && $title !== '') $name = $this->cleanSharedName($title);

        session()->put('device_lead_import', [
            'name' => $name, 'phone' => $phone, 'email' => $email,
            'shared_text' => $text, 'shared_url' => $url, 'source' => 'android_share',
        ]);

        return redirect('/?device_import=1');
    }

    /** Native Android bridge fallback. The companion opens this URL after a local call-log/contact selection. */
    public function native(Request $request)
    {
        $name = $this->cleanSharedName((string) $request->query('name', ''));
        $phone = trim((string) $request->query('phone', ''));
        $email = trim((string) $request->query('email', ''));

        if ($name === '' && $phone === '') {
            return redirect('/?device_import_error=1');
        }

        session()->put('device_lead_import', [
            'name' => $name, 'phone' => $phone, 'email' => $email,
            'shared_text' => '', 'shared_url' => '', 'source' => 'android_native',
        ]);

        return redirect('/?device_import=1');
    }

    private function parseVcard(string $vcard): array
    {
        $vcard = preg_replace("/\r\n[ \t]/", '', $vcard) ?? $vcard;
        $name = $phone = $email = '';
        if (preg_match('/(?:^|\R)FN(?:;[^:]*)?:([^\r\n]+)/i', $vcard, $m)) $name = $this->unescapeVcard(trim($m[1]));
        if (preg_match('/(?:^|\R)TEL(?:;[^:]*)?:([^\r\n]+)/i', $vcard, $m)) $phone = trim($m[1]);
        if (preg_match('/(?:^|\R)EMAIL(?:;[^:]*)?:([^\r\n]+)/i', $vcard, $m)) $email = trim($m[1]);
        if ($phone === '' && preg_match('/(?:^|\R)TEL[^:]*:([^\r\n]+)/i', $vcard, $m)) $phone = trim($m[1]);
        return [$name, $phone, $email];
    }

    private function unescapeVcard(string $value): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\\;'], [" ", " ", ',', ';'], $value));
    }

    private function cleanSharedName(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return mb_substr($value, 0, 255);
    }
}
