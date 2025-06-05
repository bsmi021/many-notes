<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Support\Facades\Storage;

final readonly class UpdateVault
{
    /**
     * @param array{name?: string, templates_node_id?: int|null} $attributes
     */
    public function handle(Vault $vault, array $attributes): void
    {
        /** @var array{name: string}  $original */
        $original = $vault->toArray();
        $vault->update($attributes);

        if (!$vault->wasChanged('name')) {
            return;
        }

        /** @var User $user */
        // $user = $vault->user()->first();
        // $originalPath = (new GetPathFromVault())->handle($vault); // This would be user_id/vault_id
        // If the intention was to rename the directory based on vault name,
        // the original path should be constructed using $original['name']
        // and the new path using $vault->name.
        // However, vaults are stored by ID (e.g., user_1/vault_123), not by name.
        // Renaming a vault's name attribute should not typically rename its ID-based directory.
        // If $vault->name was part of the path, then a move operation would be needed.
        // Since it's not (paths are ID-based), no move operation on storage is required here
        // when only the 'name' attribute changes.
        // If other attributes that *do* affect path were changed, then a move would be needed.
        // For now, assuming only 'name' or 'templates_node_id' changes,
        // and neither affect the main vault directory path.

        // Example of what it might look like IF paths were name-based:
        // $userPath = (new GetPathFromUser())->handle($user);
        // Storage::disk('google')->move(
        //     $userPath . $original['name'], // old path
        //     $userPath . $vault->name       // new path
        // );
    }
}
