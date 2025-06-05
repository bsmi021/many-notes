<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\VaultNode;
use App\Services\VaultFiles\Note;
use Illuminate\Support\Facades\Storage;

final readonly class UpdateVaultNode
{
    /**
     * @param array{
     *   parent_id?: int|null,
     *   name?: string,
     *   content?: string|null
     * } $attributes
     */
    public function handle(VaultNode $node, array $attributes): void
    {
        $originalPath = (new GetPathFromVaultNode())->handle($node);

        // Determine if a move operation is implied by the attributes
        $isMoving = array_key_exists('name', $attributes) || array_key_exists('parent_id', $attributes);

        // Save node to database
        $node->update($attributes);
        // Refresh the node to ensure relations used in path generation are up-to-date
        $node->refresh();

        $newPath = (new GetPathFromVaultNode())->handle($node);

        // Handle move operation if path has changed
        if ($isMoving && $originalPath !== $newPath) {
            if ($node->is_file) {
                // If it's a file and content is also changing,
                // it might be better to write to new location directly instead of move + put.
                // However, standard practice is move then update content if needed.
                // For simplicity with Flysystem, a move is generally okay.
                // If the file previously existed at originalPath, move it.
                if (Storage::disk('google')->exists($originalPath)) {
                    Storage::disk('google')->move($originalPath, $newPath);
                }
            } else { // It's a directory
                if (Storage::disk('google')->exists($originalPath)) {
                    Storage::disk('google')->move($originalPath, $newPath);
                } else {
                    // If original directory didn't exist (e.g. new parent), create the new one
                    Storage::disk('google')->createDirectory($newPath);
                }
            }
        }

        // Save content to disk if it's a file, a note, and content is provided
        if ($node->is_file && in_array($node->extension, Note::extensions()) && array_key_exists('content', $attributes)) {
            // Always write to the new/current path
            Storage::disk('google')->put($newPath, $attributes['content'] ?? '');
        }
    }
}
