<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Vault;
use App\Models\VaultNode;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Staudenmeir\LaravelAdjacencyList\Eloquent\Collection;
use ZipArchive;

final readonly class ExportVault
{
    public function handle(Vault $vault): string
    {
        $zip = new ZipArchive();
        $relativePath = 'public/' . Str::random(16) . '.zip';
        $path = Storage::disk('local')->path($relativePath);
        $nodes = $vault->nodes()->whereNull('parent_id')->get();

        if ($nodes->count() === 0) {
            throw new Exception(__('Your vault is empty'));
        }

        Storage::disk('local')->put($relativePath, '');

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception(__('Something went wrong'));
        }

        $this->exportNodes($zip, $nodes);
        $zip->close();

        return $path;
    }

    /**
     * @param Collection<int, VaultNode> $nodes
     */
    private function exportNodes(ZipArchive &$zip, Collection $nodes, string $path = ''): void
    {
        foreach ($nodes as $node) {
            $nodePath = mb_ltrim("$path/$node->name", '/');
            $nodePath .= $node->is_file ? ".$node->extension" : '';
            $diskPath = (new GetPathFromVaultNode())->handle($node); // Path on the storage disk

            if ($node->is_file && $node->extension === 'md') {
                // Markdown files are taken from the database content
                $zip->addFromString($nodePath, (string) $node->content);
            } else {
                // For folders or non-markdown files, check existence on the 'google' disk
                if (!Storage::disk('google')->exists($diskPath)) {
                    throw new Exception(
                        sprintf(
                            "%s missing on disk: %s (expected at %s)",
                            $node->is_file ? 'File' : 'Folder',
                            $nodePath, // User-facing path in zip
                            $diskPath  // Actual path on disk for debugging
                        ),
                    );
                }

                if (!$node->is_file) {
                    $zip->addEmptyDir($nodePath);
                    if ($node->children()->count()) {
                        $this->exportNodes($zip, $node->children()->get(), $nodePath);
                    }
                } else {
                    // Non-markdown files: get content from 'google' disk and add to zip
                    $fileContent = Storage::disk('google')->get($diskPath);
                    if ($fileContent === null) {
                        // This case should ideally be caught by the 'exists' check above,
                        // but as a safeguard:
                        throw new Exception(
                            sprintf(
                                "File content could not be read from disk: %s (expected at %s)",
                                $nodePath,
                                $diskPath
                            ),
                        );
                    }
                    $zip->addFromString($nodePath, $fileContent);
                }
            }
        }
    }
}
