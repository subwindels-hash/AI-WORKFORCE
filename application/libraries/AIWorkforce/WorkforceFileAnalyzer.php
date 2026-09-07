<?php
namespace AIWorkforce;

use AIWorkforce\Providers\SpeechToTextProvider;

/**
 * Extracts safe, grounded context from a user-uploaded workforce attachment so
 * a specialist agent can analyse the file without mixing chats or inventing
 * content. Text documents are read directly; images are parsed for metadata,
 * dimensions, EXIF and embedded/OCR text; audio is transcribed through the
 * configured STT provider; video is transcribed by extracting its audio track
 * first when ffmpeg is available.
 */
final class WorkforceFileAnalyzer
{
    public const MAX_BYTES = 26214400; // 25 MB
    private const MAX_EXCERPT_CHARS = 12000;

    /** @var array<int,string> */
    private const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm',
        'log', 'yaml', 'yml', 'ini', 'sql', 'srt', 'vtt', 'rtf'
    ];

    /** @var array<int,string> */
    private const DOCUMENT_EXTENSIONS = ['pdf', 'docx'];

    /** @var array<int,string> */
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'tiff', 'tif', 'ico', 'heic', 'heif'
    ];

    /** @var array<int,string> */
    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'flac', 'opus', 'webm'];

    /** @var array<int,string> */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'avi', 'mkv', 'webm'];

    /**
     * @param array<string,mixed> $file one $_FILES entry
     * @return array<string,mixed>
     */
    public function analyze(array $file): array
    {
        $uploaded = $this->validate($file);
        $kind = $this->classify($uploaded['extension'], $uploaded['mime']);
        $attachment = [
            'name' => $uploaded['name'],
            'size' => $uploaded['size'],
            'mime' => $uploaded['mime'],
            'extension' => $uploaded['extension'],
            'kind' => $kind,
        ];
        $warnings = [];
        $extracted = '';
        $detectedLanguage = null;
        $source = 'text';

        if ($kind === 'text') {
            $extracted = $this->extractPlainText($uploaded['tmp'], $uploaded['extension']);
        } elseif ($kind === 'document') {
            if ($uploaded['extension'] === 'docx') {
                $extracted = $this->extractDocxText($uploaded['tmp']);
            } elseif ($uploaded['extension'] === 'pdf') {
                $extracted = $this->extractPdfText($uploaded['tmp']);
            }
        } elseif ($kind === 'image') {
            $extracted = $this->extractImageDetails($uploaded['tmp'], $uploaded['extension'], $uploaded['mime'], $uploaded['name']);
            $source = 'image';
        } elseif ($kind === 'audio') {
            $transcript = $this->transcribeAudio($uploaded['tmp'], $uploaded['name'], $uploaded['mime']);
            $extracted = (string) ($transcript['text'] ?? '');
            $detectedLanguage = $transcript['language'] ?? null;
            $source = 'transcript';
        } elseif ($kind === 'video') {
            $audio = $this->extractVideoAudio($uploaded['tmp']);
            $warnings = array_merge($warnings, (array) ($audio['warnings'] ?? []));
            try {
                $transcript = $this->transcribeAudio((string) $audio['path'], $uploaded['name'] . '.wav', 'audio/wav');
            } finally {
                if (!empty($audio['path']) && is_file((string) $audio['path'])) @unlink((string) $audio['path']);
            }
            $extracted = (string) ($transcript['text'] ?? '');
            $detectedLanguage = $transcript['language'] ?? null;
            $source = 'transcript';
        }

        $normalized = $this->normalizeText($extracted);
        if ($normalized === '') {
            throw new \RuntimeException(match ($kind) {
                'audio' => 'No speech was detected in that audio file. Try a clearer recording or a supported spoken-audio file.',
                'video' => 'No spoken audio was extracted from that video. Try a video with clear speech or upload the audio track directly.',
                'document' => 'No readable text could be extracted from that document. Try DOCX/TXT, or ask an administrator to enable PDF text extraction utilities.',
                'image' => 'The uploaded image could not be processed or contained no readable image metadata.',
                default => 'That file did not contain readable text for analysis.',
            });
        }

        $fullLength = mb_strlen($normalized);
        $excerpt = $this->excerpt($normalized, self::MAX_EXCERPT_CHARS);
        $truncated = mb_strlen($excerpt) < $fullLength;
        $kindLabel = $kind === 'document' ? 'document' : ($kind === 'image' ? 'image' : $kind);
        $sourceLabel = $source === 'transcript' ? 'transcript' : ($source === 'image' ? 'image metadata & content' : 'text');
        $contextMessage = sprintf(
            'Attached file "%s" (%s, %s, %s). Extracted %s%s: %s',
            $attachment['name'],
            $kindLabel,
            $attachment['extension'] !== '' ? '.' . $attachment['extension'] : ($attachment['mime'] ?: 'file'),
            self::humanBytes((int) $attachment['size']),
            $sourceLabel,
            $truncated ? ' — truncated for chat context' : '',
            $excerpt
        );

        return [
            'attachment' => $attachment,
            'facts' => [
                'uploadedFile' => $attachment,
                'extractedContent' => [
                    'type' => $sourceLabel,
                    'text' => $excerpt,
                    'characters' => $fullLength,
                    'truncated' => $truncated,
                    'language' => $detectedLanguage,
                    'warnings' => $warnings,
                ],
                'instructions' => [
                    'analyse_only_the_uploaded_content' => true,
                    'call_out_any_extraction_limitations' => true,
                ],
            ],
            'contextMessage' => $contextMessage,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string,mixed> $file @return array{name:string,tmp:string,size:int,mime:string,extension:string} */
    private function validate(array $file): array
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Choose a file to analyse.');
        }
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \RuntimeException('The file is too large. Use a file under 25 MB.');
        }
        if ($code !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('The file could not be uploaded. Please try again.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size <= 0) {
            throw new \RuntimeException('The uploaded file was empty or unavailable.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('The file is too large. Use a file under 25 MB.');
        }
        $name = trim((string) ($file['name'] ?? 'upload'));
        if ($name === '') $name = 'upload';
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'upload';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $this->sniffMime($tmp);
        $kind = $this->classify($extension, $mime);
        if ($kind === 'unsupported') {
            throw new \RuntimeException('This file type is not supported yet. Upload TXT, MD, CSV, JSON, DOCX, PDF, JPG, PNG, WEBP, GIF, BMP, SVG, TIFF, MP3, WAV, M4A, OGG, MP4, MOV, AVI, MKV or WEBM.');
        }
        return ['name' => $name, 'tmp' => $tmp, 'size' => $size, 'mime' => $mime, 'extension' => $extension];
    }

    private function sniffMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime !== '') return $mime;
            }
        }
        return 'application/octet-stream';
    }

    private function classify(string $extension, string $mime): string
    {
        $extension = strtolower($extension);
        $mime = strtolower($mime);
        if (in_array($extension, self::TEXT_EXTENSIONS, true)) return 'text';
        if (in_array($extension, self::DOCUMENT_EXTENSIONS, true)) return 'document';
        if (in_array($extension, self::IMAGE_EXTENSIONS, true) || str_starts_with($mime, 'image/')) return 'image';
        if (in_array($extension, self::AUDIO_EXTENSIONS, true) || str_starts_with($mime, 'audio/')) return 'audio';
        if (in_array($extension, self::VIDEO_EXTENSIONS, true) || str_starts_with($mime, 'video/')) return 'video';
        return 'unsupported';
    }

    private function extractPlainText(string $path, string $extension): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('The uploaded text file could not be read.');
        }
        if ($extension === 'json') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($pretty) && $pretty !== '') $raw = $pretty;
            }
        }
        if ($extension === 'rtf') {
            $raw = preg_replace('/\\{\\\\.*?\\}|\\\\[a-z]+-?\d* ?|[{}]/', ' ', $raw) ?? $raw;
        }
        return $this->toUtf8($raw);
    }

    private function extractDocxText(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('DOCX analysis needs the ZipArchive PHP extension on the server.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The DOCX file could not be opened.');
        }
        $chunks = [];
        for ($i = 0; $i < $zip->numFiles; $i += 1) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header\d+|footer\d+)\.xml$#i', $name)) continue;
            $xml = (string) $zip->getFromIndex($i);
            if ($xml === '') continue;
            $xml = str_replace(['</w:p>', '</w:tr>', '</w:tbl>'], ["\n", "\n", "\n"], $xml);
            $text = strip_tags($xml);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (trim($text) !== '') $chunks[] = $text;
        }
        $zip->close();
        return implode("\n\n", $chunks);
    }

    private function extractPdfText(string $path): string
    {
        if (!$this->commandExists('pdftotext')) {
            throw new \RuntimeException('PDF analysis requires the pdftotext utility on the server. Upload the text as DOCX/TXT, or ask an administrator to enable PDF extraction.');
        }
        $out = $this->runCommand('pdftotext -layout -nopgbrk ' . escapeshellarg($path) . ' -');
        if (trim($out) === '') {
            throw new \RuntimeException('No readable text was extracted from that PDF. It may be image-only or protected.');
        }
        return $out;
    }

    /**
     * Extracts rich metadata, EXIF, structural text (SVG), and OCR content from an uploaded image.
     */
    private function extractImageDetails(string $path, string $extension, string $mime, string $name): string
    {
        $lines = [];
        $lines[] = "Image File: {$name}";
        $lines[] = "File Format: " . strtoupper($extension ?: 'image') . ($mime ? " ({$mime})" : '');

        // SVG vector graphic handling
        if ($extension === 'svg' || $mime === 'image/svg+xml') {
            $svgContent = @file_get_contents($path);
            if ($svgContent !== false) {
                $lines[] = "Graphic Type: Scalable Vector Graphics (SVG)";
                $svgText = [];
                if (preg_match_all('#<(?:text|tspan|title|desc)[^>]*>(.*?)</(?:text|tspan|title|desc)>#is', $svgContent, $matches)) {
                    foreach ($matches[1] as $match) {
                        $clean = trim(strip_tags($match));
                        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        if ($clean !== '') $svgText[] = $clean;
                    }
                }
                if (!empty($svgText)) {
                    $lines[] = "SVG Text Content:\n" . implode("\n", array_unique($svgText));
                }
                if (preg_match('#viewBox=["\']([^"\']+)["\']#i', $svgContent, $vb)) {
                    $lines[] = "ViewBox: " . trim($vb[1]);
                }
                if (preg_match('#width=["\']([^"\']+)["\']#i', $svgContent, $w)) {
                    $lines[] = "Width attribute: " . trim($w[1]);
                }
                if (preg_match('#height=["\']([^"\']+)["\']#i', $svgContent, $h)) {
                    $lines[] = "Height attribute: " . trim($h[1]);
                }
            }
        } else {
            // Raster image handling
            $imgInfo = @getimagesize($path);
            if (is_array($imgInfo)) {
                $width = (int) ($imgInfo[0] ?? 0);
                $height = (int) ($imgInfo[1] ?? 0);
                if ($width > 0 && $height > 0) {
                    $orientation = $width > $height ? 'Landscape' : ($height > $width ? 'Portrait' : 'Square');
                    $gcd = $this->calculateGcd($width, $height);
                    $aspectRatio = ($gcd > 0) ? ($width / $gcd) . ':' . ($height / $gcd) : "{$width}:{$height}";
                    $lines[] = "Dimensions: {$width} x {$height} pixels ({$orientation}, Aspect Ratio: {$aspectRatio})";
                }
                if (!empty($imgInfo['bits'])) {
                    $lines[] = "Color Depth: {$imgInfo['bits']} bits";
                }
                if (!empty($imgInfo['channels'])) {
                    $channels = $imgInfo['channels'] === 3 ? 'RGB' : ($imgInfo['channels'] === 4 ? 'CMYK' : $imgInfo['channels'] . ' channels');
                    $lines[] = "Color Model: {$channels}";
                }
            }

            // EXIF metadata extraction
            if (function_exists('exif_read_data') && in_array($extension, ['jpg', 'jpeg', 'tiff', 'tif', 'webp'], true)) {
                $exif = @exif_read_data($path, null, true);
                if (is_array($exif)) {
                    $meta = [];
                    foreach (['IFD0', 'EXIF', 'COMPUTED'] as $section) {
                        if (!isset($exif[$section]) || !is_array($exif[$section])) continue;
                        foreach ($exif[$section] as $k => $v) {
                            if (is_string($v) || is_numeric($v)) {
                                $kLower = strtolower((string)$k);
                                if (in_array($kLower, ['make', 'model', 'datetimeoriginal', 'software', 'artist', 'copyright', 'imagedescription', 'exposuretime', 'fnumber', 'isospeedratings', 'focallength'], true)) {
                                    $cleanVal = trim((string) $v);
                                    if ($cleanVal !== '') $meta[$k] = $cleanVal;
                                }
                            }
                        }
                    }
                    if (!empty($meta)) {
                        $metaLines = [];
                        foreach ($meta as $k => $v) {
                            $metaLines[] = "  - {$k}: {$v}";
                        }
                        $lines[] = "Image Metadata / EXIF:\n" . implode("\n", $metaLines);
                    }
                }
            }

            // OCR extraction if tesseract utility is present
            if ($this->commandExists('tesseract')) {
                try {
                    $ocrOut = $this->runCommand('tesseract ' . escapeshellarg($path) . ' stdout --oem 1 -l eng 2>/dev/null');
                    $cleanOcr = trim($ocrOut);
                    if ($cleanOcr !== '') {
                        $lines[] = "Extracted Text (OCR):\n" . $cleanOcr;
                    }
                } catch (\Throwable $e) {
                    // OCR is best-effort
                }
            }
        }

        return implode("\n", $lines);
    }

    private function calculateGcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $t = $b;
            $b = $a % $b;
            $a = $t;
        }
        return $a;
    }

    /** @return array{path:string,warnings:array<int,string>} */
    private function extractVideoAudio(string $path): array
    {
        if (!$this->commandExists('ffmpeg')) {
            throw new \RuntimeException('Video analysis requires ffmpeg on the server so audio can be extracted first. Upload the audio track directly, or ask an administrator to enable ffmpeg.');
        }
        $wav = tempnam(sys_get_temp_dir(), 'wf_vid_');
        if ($wav === false) {
            throw new \RuntimeException('Temporary storage for video analysis is unavailable.');
        }
        @unlink($wav);
        $wav .= '.wav';
        $this->runCommand('ffmpeg -y -i ' . escapeshellarg($path) . ' -vn -ac 1 -ar 16000 -f wav ' . escapeshellarg($wav));
        if (!is_file($wav) || (int) @filesize($wav) <= 0) {
            @unlink($wav);
            throw new \RuntimeException('The video audio track could not be extracted.');
        }
        return ['path' => $wav, 'warnings' => []];
    }

    /** @return array<string,mixed> */
    private function transcribeAudio(string $path, string $filename, string $mime): array
    {
        $cfg = $this->resolveSttConfig();
        if (!$cfg) {
            throw new \RuntimeException('Speech-to-text is not configured. Ask an administrator to enable an STT provider to analyse audio or video files.');
        }
        $provider = new SpeechToTextProvider($cfg);
        $result = $provider->transcribeFile($path, null, $filename, $mime);
        if (!is_array($result) || !empty($result['error'])) {
            $error = is_array($result) ? (string) ($result['error'] ?? 'Audio transcription failed.') : 'Audio transcription failed.';
            throw new \RuntimeException(ApiProviders::providerMessage($error));
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function resolveSttConfig(): ?array
    {
        $direct = ApiProviders::resolve('stt');
        if (is_array($direct) && ($direct['driver'] ?? '') === 'openai_compatible' && !empty($direct['secrets']['api_key'])) {
            return $direct;
        }
        foreach (['llm', 'language_ai'] as $service) {
            $fallback = ApiProviders::resolve($service);
            if (!is_array($fallback)) continue;
            if (($fallback['driver'] ?? '') !== 'openai_compatible' || empty($fallback['secrets']['api_key'])) continue;
            return [
                'driver' => 'openai_compatible',
                'base_url' => $fallback['base_url'] ?? 'https://api.openai.com/v1',
                'secrets' => $fallback['secrets'] ?? [],
                'extra' => $fallback['extra'] ?? [],
            ];
        }
        return null;
    }

    private function toUtf8(string $raw): string
    {
        if ($raw === '') return '';
        if (function_exists('mb_check_encoding') && mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
        }
        return $raw;
    }

    private function normalizeText(string $text): string
    {
        $text = $this->toUtf8($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function excerpt(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $maxChars) return $text;
        $head = (int) floor($maxChars * 0.7);
        $tail = max(0, $maxChars - $head - 24);
        return trim(mb_substr($text, 0, $head))
            . "\n\n[... truncated ...]\n\n"
            . trim(mb_substr($text, -$tail));
    }

    private function commandExists(string $command): bool
    {
        if (!function_exists('exec')) return false;
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out)) return true;
        $out = [];
        $code = 1;
        @exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    private function runCommand(string $command): string
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('Server command execution is unavailable for this file type.');
        }
        $output = [];
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);
        $text = trim(implode("\n", $output));
        if ($code !== 0) {
            throw new \RuntimeException($text !== '' ? mb_substr($text, 0, 240) : 'The server could not process that file.');
        }
        return $text;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return number_format($value, $value >= 100 ? 0 : 1) . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }
}
