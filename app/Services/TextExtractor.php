<?php

namespace App\Services;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class TextExtractor
{
    public function extract(string $absolutePath, string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => $this->extractPdf($absolutePath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($absolutePath),
            default => $this->extractPlainText($absolutePath),
        };
    }

    private function extractPdf(string $path): string
    {
        $parser = new PdfParser;

        return $parser->parseFile($path)->getText();
    }

    private function extractDocx(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open DOCX file: {$path}");
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException("DOCX file missing document.xml: {$path}");
        }

        $xml = preg_replace('/<w:p[ >]/', "\n$0", $xml);
        $xml = preg_replace('/<w:tab[ \/>]/', "\t$0", $xml);
        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function extractPlainText(string $path): string
    {
        return file_get_contents($path);
    }
}
