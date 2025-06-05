<?php

declare(strict_types=1);

namespace App\Filesystem;

use Google_Service_Drive;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;

class GoogleDriveAdapter implements FilesystemAdapter
{
    protected Google_Service_Drive $service;

    public function __construct(Google_Service_Drive $service)
    {
        $this->service = $service;
    }

    // $lookingForFolder: bool - if true, query specifically for folder mimeType. Otherwise, queries for any non-folder type.
    // When null (default), queries for any type (useful for generic existence or getting ID of unknown type).
    private function _getFileIdByPath(string $path, string $parentFolderId = 'appDataFolder', ?bool $lookingForFolder = null): ?string
    {
        $pathParts = explode('/', trim($path, '/'));
        $name = array_pop($pathParts); // This is the target name
        $currentParentId = $parentFolderId;

        // Traverse the path to find the ID of the immediate parent of the target
        foreach ($pathParts as $folderName) {
            if (empty($folderName)) continue;

            $query = "mimeType = 'application/vnd.google-apps.folder' and name = '" . addslashes($folderName) . "' and '" . $currentParentId . "' in parents and trashed = false";
            $optParams = [
                'spaces' => 'appDataFolder',
                'fields' => 'files(id)',
                'q' => $query,
            ];
            $results = $this->service->files->listFiles($optParams);
            if (count($results->getFiles()) === 0) {
                return null; // Parent folder in the path not found
            }
            $currentParentId = $results->getFiles()[0]->getId();
        }

        // Now search for the target file or folder within the determined parent
        $fileQuery = "name = '" . addslashes($name) . "' and '" . $currentParentId . "' in parents and trashed = false";
        if ($lookingForFolder !== null) {
            $fileQuery .= $lookingForFolder
                ? " and mimeType = 'application/vnd.google-apps.folder'"
                : " and mimeType != 'application/vnd.google-apps.folder'";
        }

        $fileOptParams = [
            'spaces' => 'appDataFolder',
            'fields' => 'files(id)',
            'q' => $fileQuery,
        ];
        $fileResults = $this->service->files->listFiles($fileOptParams);

        if (count($fileResults->getFiles()) > 0) {
            return $fileResults->getFiles()[0]->getId();
        }

        return null;
    }

    private function _getOrCreateParentFolderId(string $path): string
    {
        $pathParts = explode('/', trim($path, '/'));
        array_pop($pathParts); // Remove filename part

        $currentParentId = 'appDataFolder';
        if (empty($pathParts)) {
            return $currentParentId; // File is in root of appDataFolder
        }

        foreach ($pathParts as $folderName) {
            if (empty($folderName)) continue;

            $query = "mimeType = 'application/vnd.google-apps.folder' and name = '" . addslashes($folderName) . "' and '" . $currentParentId . "' in parents and trashed = false";
            $optParams = [
                'spaces' => 'appDataFolder',
                'fields' => 'files(id)',
                'q' => $query,
            ];

            $results = $this->service->files->listFiles($optParams);
            if (count($results->getFiles()) > 0) {
                $currentParentId = $results->getFiles()[0]->getId();
            } else {
                // Folder does not exist, create it
                $folder = new \Google_Service_Drive_DriveFile();
                $folder->setName($folderName);
                $folder->setMimeType('application/vnd.google-apps.folder');
                $folder->setParents([$currentParentId]);

                $createdFolder = $this->service->files->create($folder, ['fields' => 'id']);
                $currentParentId = $createdFolder->getId();
            }
        }
        return $currentParentId;
    }

    private function _getParentIdAndBaseName(string $path): array
    {
        $path = trim($path, '/');
        $baseName = basename($path);
        $parentPath = dirname($path);

        if ($parentPath === '.' || $parentPath === $baseName) { // Root or no parent path
            $parentId = 'appDataFolder';
        } else {
            $parentId = $this->_getOrCreateParentFolderId($parentPath . '/'); // Ensure trailing slash for _getOrCreateParentFolderId
        }
        return ['parentId' => $parentId, 'baseName' => $baseName];
    }

    public function fileExists(string $path): bool
    {
        try {
            $fileId = $this->_getFileIdByPath($path, 'appDataFolder', false); // false: looking for a file
            return $fileId !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $folderId = $this->_getFileIdByPath($path, 'appDataFolder', true); // true: looking for a folder
            if ($folderId === null) return false;

            // Optional: an additional get call to be absolutely sure, though _getFileIdByPath with type filter should suffice.
            // For robustness, especially if _getFileIdByPath's type filter was less strict, this would be good.
            // Given the current strictness, this 'get' might be redundant but safe.
            $file = $this->service->files->get($folderId, ['fields' => 'mimeType']); // Confirm with a get
            return $file->getMimeType() === 'application/vnd.google-apps.folder';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $fileId = $this->_getFileIdByPath($path, 'appDataFolder', false); // false: looking for a file to overwrite
        $fileName = basename($path);

        $driveFile = new \Google_Service_Drive_DriveFile();
        $driveFile->setName($fileName);

        $uploadType = 'media';
        // $uploadType = 'multipart'; // If also setting metadata in the same call

        if ($fileId !== null) {
            // File exists, update it
            try {
                $this->service->files->update($fileId, $driveFile, [
                    'data' => $contents,
                    'uploadType' => $uploadType,
                    // 'fields' => 'id', // Optional: fields to return
                ]);
            } catch (\Exception $e) {
                // Handle error (e.g., throw Flysystem exception)
                throw new \League\Flysystem\UnableToWriteFile("Could not update file: {$path}. Error: {$e->getMessage()}", 0, $e);
            }
        } else {
            // File does not exist, create it
            $parentId = $this->_getOrCreateParentFolderId($path);
            $driveFile->setParents([$parentId]);
            try {
                $this->service->files->create($driveFile, [
                    'data' => $contents,
                    'uploadType' => $uploadType,
                    // 'fields' => 'id', // Optional: fields to return
                ]);
            } catch (\Exception $e) {
                // Handle error
                throw new \League\Flysystem\UnableToWriteFile("Could not create file: {$path}. Error: {$e->getMessage()}", 0, $e);
            }
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        // TODO: Implement writeStream() method.
    }

    public function read(string $path): string
    {
        $fileId = $this->_getFileIdByPath($path);

        if ($fileId === null) {
            throw \League\Flysystem\UnableToReadFile::fromLocation($path, 'File not found.');
        }

        try {
            $response = $this->service->files->get($fileId, ['alt' => 'media']);
            // The response body from Google API client is directly the content for 'media' download,
            // but it might also be a Psr\Http\Message\StreamInterface with Guzzle 7+
            if ($response instanceof \Psr\Http\Message\StreamInterface) {
                return $response->getContents();
            }
            // For older versions or direct media downloads, it might be the string itself.
            // This part might need adjustment based on the actual Google Client version's return type.
            // Assuming it's string content for now based on typical simple 'media' downloads.
            return (string) $response;
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToReadFile::fromLocation($path, "Could not read file: {$e->getMessage()}", $e);
        }
    }

    public function readStream(string $path)
    {
        // TODO: Implement readStream() method.
        return false;
    }

    public function delete(string $path): void
    {
        $fileId = $this->_getFileIdByPath($path);

        if ($fileId === null) {
            // File not found, Flysystem expects delete to be idempotent
            return;
        }

        try {
            $this->service->files->delete($fileId);
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToDeleteFile::fromLocation($path, "Could not delete file: {$e->getMessage()}", $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = trim($path, '/');
        if (empty($path)) {
            // Cannot delete root 'appDataFolder' itself or an empty path
            throw \League\Flysystem\UnableToDeleteDirectory::atLocation($path, 'Path cannot be empty or root.');
        }

        $folderId = null;
        try {
            // First, check if it exists and is a directory
            $folderId = $this->_getFileIdByPath($path);
            if ($folderId === null) {
                return; // Directory not found, idempotent delete
            }

            $file = $this->service->files->get($folderId, ['fields' => 'mimeType, id']);
            if ($file->getMimeType() !== 'application/vnd.google-apps.folder') {
                throw \League\Flysystem\UnableToDeleteDirectory::atLocation($path, 'Path is not a directory.');
            }

            // Attempt to delete. Google Drive API will fail if the folder is not empty.
            $this->service->files->delete($folderId);

        } catch (\Google\Service\Exception $e) {
            // Check if the error is because the folder is not empty
            // Google API typically returns a 400 or 403 error with a reason like 'folderNotEmpty'
            // This error checking is specific to Google's API client and might need adjustment
            if ($e->getCode() == 400 || $e->getCode() == 403) { // Simplified check
                 $errors = $e->getErrors();
                 if (is_array($errors) && !empty($errors) && isset($errors[0]['reason']) && $errors[0]['reason'] === 'folderNotEmpty') {
                     throw \League\Flysystem\UnableToDeleteDirectory::because($path, "Directory is not empty.", $e);
                 }
            }
            throw \League\Flysystem\UnableToDeleteDirectory::dueToFailure($path, $e);
        } catch (\Exception $e) { // Catch other general exceptions
            if ($folderId === null && $e instanceof \League\Flysystem\FilesystemOperationFailed) {
                // This might happen if _getFileIdByPath throws an error before folderId is found
                return; // Path doesn't exist, treat as success for deleteDirectory
            }
            throw \League\Flysystem\UnableToDeleteDirectory::dueToFailure($path, $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $path = trim($path, '/');
        if (empty($path)) {
            throw \League\Flysystem\UnableToCreateDirectory::atLocation($path, 'Path cannot be empty.');
        }

        // Check if directory already exists using the specific type check
        if ($this->_getFileIdByPath($path, 'appDataFolder', true) !== null) {
            // To be absolutely sure it's a directory and not a file with same name,
            // directoryExists() is more robust if it performs a get and checks mimeType.
            // Assuming directoryExists is robust:
             if ($this->directoryExists($path)) {
                 return; // Succeed if directory already exists
             }
        }

        $parts = $this->_getParentIdAndBaseName($path);
        $parentId = $parts['parentId'];
        $name = $parts['baseName'];

        $folder = new \Google_Service_Drive_DriveFile();
        $folder->setName($name);
        $folder->setMimeType('application/vnd.google-apps.folder');
        $folder->setParents([$parentId]);

        try {
            $this->service->files->create($folder, ['fields' => 'id']);
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToCreateDirectory::dueToFailure($path, $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // appDataFolder has fixed visibility, so this is a no-op.
        // We need to ensure the path exists, otherwise Flysystem might expect an error.
        if ($this->_getFileIdByPath($path) === null) {
            throw \League\Flysystem\UnableToSetVisibility::atLocation($path, 'File or directory not found.');
        }
        // If one wanted to be strict:
        // if ($visibility !== \League\Flysystem\Visibility::PRIVATE) {
        //     throw \League\Flysystem\UnableToSetVisibility::atLocation($path, 'Visibility cannot be changed for appDataFolder items.');
        // }
    }

    public function visibility(string $path): FileAttributes
    {
        if ($this->_getFileIdByPath($path) === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'File or directory not found.');
        }
        // appDataFolder contents are inherently private to the application
        return new FileAttributes($path, null, \League\Flysystem\Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $details = $this->_getFileDetails($path);
        if ($details === null || !isset($details['file'])) {
            throw UnableToRetrieveMetadata::mimeType($path, 'File or directory not found.');
        }
        /** @var \Google_Service_Drive_DriveFile $googleFile */
        $googleFile = $details['file'];
        return new FileAttributes($path, null, null, null, $googleFile->getMimeType());
    }

    public function lastModified(string $path): FileAttributes
    {
        $details = $this->_getFileDetails($path);
        if ($details === null || !isset($details['file'])) {
            throw UnableToRetrieveMetadata::lastModified($path, 'File or directory not found.');
        }
        /** @var \Google_Service_Drive_DriveFile $googleFile */
        $googleFile = $details['file'];
        $timestamp = strtotime($googleFile->getModifiedTime());

        if ($timestamp === false) {
            throw UnableToRetrieveMetadata::lastModified($path, 'Invalid modified time format from API.');
        }
        return new FileAttributes($path, null, null, $timestamp);
    }

    public function fileSize(string $path): FileAttributes
    {
        $fileId = $this->_getFileIdByPath($path);
        if ($fileId === null) {
            throw UnableToRetrieveMetadata::fileSize($path, "File not found.");
        }
        try {
            $googleFile = $this->service->files->get($fileId, ['fields' => 'size,mimeType']);
            if ($googleFile->getMimeType() === 'application/vnd.google-apps.folder') {
                 throw UnableToRetrieveMetadata::fileSize($path, "Path is a directory.");
            }
            return new FileAttributes($path, (int) $googleFile->getSize());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::fileSize($path, "Could not get file size: {$e->getMessage()}", $e);
        }
    }

    protected function _mapGoogleDriveFileToAttributes(\Google_Service_Drive_DriveFile $googleFile, string $path): FileAttributes
    {
        $type = ($googleFile->getMimeType() === 'application/vnd.google-apps.folder') ? 'dir' : 'file';
        $lastModified = strtotime($googleFile->getModifiedTime()); // Assuming modifiedTime is a valid date string

        return new FileAttributes(
            $path,
            $type === 'file' ? (int) $googleFile->getSize() : null,
            null, // Visibility is not directly supported or applicable in the same way for appDataFolder
            $lastModified ?: null,
            $type === 'file' ? $googleFile->getMimeType() : null
            // No extra metadata by default
        );
    }


    public function listContents(string $directory, bool $deep): iterable
    {
        $directory = trim($directory, '/');
        $folderId = 'appDataFolder';

        if ($directory !== '') {
            $folderId = $this->_getFileIdByPath($directory);
            if ($folderId === null) {
                // Directory does not exist, or it's not a folder type we can list
                // To be more precise, one might want to use directoryExists here
                return [];
            }
            // Confirm it's a folder if an ID was found
            try {
                 $folderMeta = $this->service->files->get($folderId, ['fields' => 'mimeType']);
                 if ($folderMeta->getMimeType() !== 'application/vnd.google-apps.folder') {
                     return []; // Path exists but is not a directory
                 }
            } catch (\Exception $e) {
                 return []; // Error fetching metadata, treat as non-listable or non-existent
            }
        }

        $results = [];
        $pageToken = null;

        try {
            do {
                $query = "'" . $folderId . "' in parents and trashed = false";
                $optParams = [
                    'spaces' => 'appDataFolder',
                    'fields' => 'files(id, name, mimeType, modifiedTime, size), nextPageToken',
                    'q' => $query,
                    'pageToken' => $pageToken,
                ];

                $response = $this->service->files->listFiles($optParams);

                foreach ($response->getFiles() as $googleFile) {
                    $itemPath = $directory === '' ? $googleFile->getName() : $directory . '/' . $googleFile->getName();
                    $results[] = $this->_mapGoogleDriveFileToAttributes($googleFile, $itemPath);

                    if ($deep && $googleFile->getMimeType() === 'application/vnd.google-apps.folder') {
                        // Recursively list contents of subdirectory
                        // Note: $itemPath here is the full path of the subdirectory
                        $subDirContents = $this->listContents($itemPath, true);
                        foreach ($subDirContents as $subItem) {
                            $results[] = $subItem; // Add items from subdirectory
                        }
                    }
                }
                $pageToken = $response->getNextPageToken();
            } while ($pageToken !== null);
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToListContents::atLocation($directory, $deep, $e);
        }

        return $results;
    }

    // Helper to get file details including parent ID.
    // Returns an array ['fileId' => id, 'parentId' => parentId, 'file' => Google_Service_Drive_DriveFile] or null
    private function _getFileDetails(string $path): ?array
    {
        $path = trim($path, '/');
        $fileName = basename($path);
        $parentPath = dirname($path);

        $currentParentId = 'appDataFolder';
        if ($parentPath !== '.' && $parentPath !== $fileName) {
            $currentParentId = $this->_getFileIdByPath($parentPath, 'appDataFolder', true); // true to indicate we need folder ID
            if ($currentParentId === null) {
                return null; // Parent path does not exist
            }
        }

        $query = "name = '" . addslashes($fileName) . "' and '" . $currentParentId . "' in parents and trashed = false";
        $optParams = [
            'spaces' => 'appDataFolder',
            'fields' => 'files(id, name, mimeType, parents)', // Fetch parents to get original parentId
            'q' => $query,
        ];

        $results = $this->service->files->listFiles($optParams);
        if (count($results->getFiles()) > 0) {
            $file = $results->getFiles()[0];
            // A file in appDataFolder might have 'appDataFolder' as one of its parents, or an actual folder ID.
            // We need the specific parent ID from which to remove it.
            // If it's directly in appDataFolder, its parent for removal is 'appDataFolder'.
            $originalParentId = $currentParentId; // Default to current path's parent.
            // The 'parents' field of a file lists all its parent folders.
            // For a simple move (not duplicating), we'd typically remove from its direct resolved parent.
            return ['fileId' => $file->getId(), 'originalParentId' => $originalParentId, 'file' => $file];
        }
        return null;
    }


    public function move(string $source, string $destination, Config $config): void
    {
        $sourceDetails = $this->_getFileDetails($source);

        if ($sourceDetails === null) {
            throw \League\Flysystem\UnableToMoveFile::fromLocationTo($source, $destination, new \Exception("Source file/folder not found."));
        }

        $sourceFileId = $sourceDetails['fileId'];
        $originalParentId = $sourceDetails['originalParentId'];
        $sourceGoogleFile = $sourceDetails['file'];

        // Check if destination exists
        if ($this->_getFileIdByPath($destination) !== null) {
            throw \League\Flysystem\UnableToMoveFile::destinationExists($destination);
        }

        $destinationParts = $this->_getParentIdAndBaseName($destination);
        $newParentId = $destinationParts['parentId'];
        $newName = $destinationParts['baseName'];

        $updateFile = new \Google_Service_Drive_DriveFile();
        $updateFile->setName($newName); // Set the new name

        try {
            // If originalParentId is the same as newParentId, it's a rename within the same folder.
            // If different, it's a move to a new folder (and potentially a rename).
            $this->service->files->update($sourceFileId, $updateFile, [
                'addParents' => $newParentId,
                'removeParents' => $originalParentId, // Required to actually "move" from the old parent
                'fields' => 'id, parents', // Request fields to confirm
            ]);
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $sourceDetails = $this->_getFileDetails($source);

        if ($sourceDetails === null) {
            throw \League\Flysystem\UnableToCopyFile::fromLocationTo($source, $destination, new \Exception("Source file not found."));
        }
        $sourceFileId = $sourceDetails['fileId'];
        $sourceGoogleFile = $sourceDetails['file'];

        if ($sourceGoogleFile->getMimeType() === 'application/vnd.google-apps.folder') {
            throw \League\Flysystem\UnableToCopyFile::fromLocationTo($source, $destination, new \Exception("Copying directories is not supported."));
        }

        if ($this->_getFileIdByPath($destination) !== null) {
            throw \League\Flysystem\UnableToCopyFile::destinationExists($destination);
        }

        $destinationParts = $this->_getParentIdAndBaseName($destination);
        $newParentId = $destinationParts['parentId'];
        $newName = $destinationParts['baseName'];

        $copiedFile = new \Google_Service_Drive_DriveFile();
        $copiedFile->setName($newName);
        $copiedFile->setParents([$newParentId]);

        try {
            $this->service->files->copy($sourceFileId, $copiedFile, ['fields' => 'id']);
        } catch (\Exception $e) {
            throw \League\Flysystem\UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }
}
