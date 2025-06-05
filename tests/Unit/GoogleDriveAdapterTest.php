<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filesystem\GoogleDriveAdapter;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use League\Flysystem\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Exception;

class GoogleDriveAdapterTest extends TestCase
{
    protected MockObject|Google_Service_Drive $driveServiceMock;
    protected MockObject $filesResourceMock;
    protected GoogleDriveAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driveServiceMock = $this->createMock(Google_Service_Drive::class);
        $this->filesResourceMock = $this->createMock(\Google_Service_Drive_Resource_Files::class);
        $this->driveServiceMock->files = $this->filesResourceMock;

        $this->adapter = new GoogleDriveAdapter($this->driveServiceMock);
    }

    // Tests for fileExists
    public function testFileExistsReturnsTrueWhenFileIsFound(): void
    {
        $mockFile = $this->createMock(Google_Service_Drive_DriveFile::class);
        $mockFile->method('getId')->willReturn('fileId123');

        $fileListMock = $this->createMock(Google_Service_Drive_FileList::class);
        $fileListMock->method('getFiles')->willReturn([$mockFile]);

        $this->filesResourceMock->expects($this->once())
            ->method('listFiles')
            ->with($this->callback(function ($optParams) {
                return $optParams['q'] === "name = 'test.txt' and 'appDataFolder' in parents and trashed = false";
            }))
            ->willReturn($fileListMock);

        $this->assertTrue($this->adapter->fileExists('test.txt'));
    }

    public function testFileExistsReturnsTrueForNestedPathWhenFileIsFound(): void
    {
        $mockFolder = $this->createMock(Google_Service_Drive_DriveFile::class);
        $mockFolder->method('getId')->willReturn('folderId123');
        $folderFileListMock = $this->createMock(Google_Service_Drive_FileList::class);
        $folderFileListMock->method('getFiles')->willReturn([$mockFolder]);

        $mockFile = $this->createMock(Google_Service_Drive_DriveFile::class);
        $mockFile->method('getId')->willReturn('fileId123');
        $fileFileListMock = $this->createMock(Google_Service_Drive_FileList::class);
        $fileFileListMock->method('getFiles')->willReturn([$mockFile]);

        $this->filesResourceMock->expects($this->exactly(2))
            ->method('listFiles')
            ->willReturnCallback(function (array $optParams) use ($folderFileListMock, $fileFileListMock) {
                if ($optParams['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'folder' and 'appDataFolder' in parents and trashed = false") {
                    return $folderFileListMock;
                }
                if ($optParams['q'] === "name = 'test.txt' and 'folderId123' in parents and trashed = false") {
                    return $fileFileListMock;
                }
                return $this->createMock(Google_Service_Drive_FileList::class); // Should not happen
            });

        $this->assertTrue($this->adapter->fileExists('folder/test.txt'));
    }


    public function testFileExistsReturnsFalseWhenFileIsNotFound(): void
    {
        $fileListMock = $this->createMock(Google_Service_Drive_FileList::class);
        $fileListMock->method('getFiles')->willReturn([]); // No files found

        $this->filesResourceMock->expects($this->once())
            ->method('listFiles')
            ->willReturn($fileListMock);

        $this->assertFalse($this->adapter->fileExists('nonexistent.txt'));
    }

    public function testFileExistsReturnsFalseWhenParentFolderIsNotFound(): void
    {
        $emptyFileList = $this->createMock(Google_Service_Drive_FileList::class);
        $emptyFileList->method('getFiles')->willReturn([]);

        $this->filesResourceMock->expects($this->once()) // Only one call, for the first part of the path
            ->method('listFiles')
            ->with($this->callback(function ($optParams) {
                // Expects query for 'parent' folder
                return $optParams['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'parent' and 'appDataFolder' in parents and trashed = false";
            }))
            ->willReturn($emptyFileList);

        $this->assertFalse($this->adapter->fileExists('parent/nonexistent.txt'));
    }

    public function testFileExistsHandlesApiError(): void
    {
        $this->filesResourceMock->expects($this->once())
            ->method('listFiles')
            ->willThrowException(new Exception('API Error'));

        // As per current implementation, it catches Exception and returns false
        $this->assertFalse($this->adapter->fileExists('test.txt'));
    }

    // Tests for write
    public function testWriteCreatesNewFileWhenNotExists(): void
    {
        $path = 'newfile.txt';
        $contents = 'Hello world';

        // Mock _getFileIdByPath to return null (file not found)
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->with($this->callback(fn($params) => $params['q'] === "name = 'newfile.txt' and 'appDataFolder' in parents and trashed = false"))
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        // Mock _getOrCreateParentFolderId (assuming root for simplicity here)
        // No listFiles call if root path

        // Mock files->create
        $this->filesResourceMock->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (Google_Service_Drive_DriveFile $driveFile) use ($path) {
                    return $driveFile->getName() === basename($path) && $driveFile->getParents() === ['appDataFolder'];
                }),
                $this->callback(function ($optParams) use ($contents) {
                    return $optParams['data'] === $contents && $optParams['uploadType'] === 'media';
                })
            )
            ->willReturn($this->createMock(Google_Service_Drive_DriveFile::class)); // Return a dummy file

        $this->adapter->write($path, $contents, new Config());
    }

    public function testWriteCreatesNewFileInNestedPathWhenNotExists(): void
    {
        $path = 'newfolder/newfile.txt';
        $contents = 'Hello world';

        // Mock _getFileIdByPath for 'newfolder/newfile.txt' - assume file does not exist in its folder
        $this->filesResourceMock->expects($this->exactly(3)) // 1 for parent in _getFileId, 1 for file in _getFileId, 1 for parent in _getOrCreateParent
            ->method('listFiles')
            ->willReturnCallback(function(array $optParams) {
                if ($optParams['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'newfolder' and 'appDataFolder' in parents and trashed = false") {
                    // This simulates the folder 'newfolder' NOT existing initially for _getFileIdByPath AND _getOrCreateParentFolderId
                    return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
                }
                 if ($optParams['q'] === "name = 'newfile.txt' and 'createdFolderId' in parents and trashed = false") { // Assuming 'createdFolderId' is returned by create folder
                    // This simulates the file 'newfile.txt' NOT existing for _getFileIdByPath
                    return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
                }
                // Default empty for other unexpected calls
                return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
            });

        // Mock create for the folder 'newfolder'
        $createdFolderMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => 'createdFolderId']);
        $this->filesResourceMock->expects($this->once()) // For creating 'newfolder'
            ->method('create')
            ->with(
                $this->callback(function(Google_Service_Drive_DriveFile $driveFile){
                    return $driveFile->getName() === 'newfolder' && $driveFile->getMimeType() === 'application/vnd.google-apps.folder';
                })
            )
            ->willReturn($createdFolderMock);

        // Mock create for the file 'newfile.txt'
        $this->filesResourceMock->expects($this->exactly(2)) // 1 for folder, 1 for file
            ->method('create')
            ->withConsecutive(
                [$this->anything()], // First call is for the folder
                [$this->callback(function (Google_Service_Drive_DriveFile $driveFile) use ($path) { // Second call for the file
                    return $driveFile->getName() === basename($path) && $driveFile->getParents() === ['createdFolderId'];
                }),
                $this->callback(function ($optParams) use ($contents) {
                    return $optParams['data'] === $contents && $optParams['uploadType'] === 'media';
                })]
            )
            ->willReturnOnConsecutiveCalls(
                $createdFolderMock, // Return for folder creation
                $this->createMock(Google_Service_Drive_DriveFile::class) // Return for file creation
            );


        $this->adapter->write($path, $contents, new Config());
    }


    public function testWriteUpdatesExistingFile(): void
    {
        $path = 'existingfile.txt';
        $contents = 'Updated content';
        $fileId = 'existingFileId123';

        // Mock _getFileIdByPath to return an ID
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->willReturn($fileListMock);

        // Mock files->update
        $this->filesResourceMock->expects($this->once())
            ->method('update')
            ->with(
                $fileId,
                $this->callback(function(Google_Service_Drive_DriveFile $driveFile) use ($path) {
                    return $driveFile->getName() === basename($path);
                }),
                $this->callback(function($optParams) use ($contents) {
                    return $optParams['data'] === $contents && $optParams['uploadType'] === 'media';
                })
            )
            ->willReturn($this->createMock(Google_Service_Drive_DriveFile::class));

        $this->adapter->write($path, $contents, new Config());
    }

    public function testWriteHandlesApiErrorOnCreate(): void
    {
        $this->expectException(\League\Flysystem\UnableToWriteFile::class);
        $this->expectExceptionMessage("Could not create file: errorfile.txt. Error: API Error on create");

        // Mock _getFileIdByPath to return null
        $this->filesResourceMock->method('listFiles')
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        // Mock files->create to throw an exception
        $this->filesResourceMock->expects($this->once())
            ->method('create')
            ->willThrowException(new Exception('API Error on create'));

        $this->adapter->write('errorfile.txt', 'error content', new Config());
    }

    public function testWriteHandlesApiErrorOnUpdate(): void
    {
        $this->expectException(\League\Flysystem\UnableToWriteFile::class);
        $this->expectExceptionMessage("Could not update file: errorfile.txt. Error: API Error on update");
        $fileId = 'errorFileId';

        // Mock _getFileIdByPath to return an ID
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->method('listFiles') // For _getFileIdByPath
            ->willReturn($fileListMock);

        // Mock files->update to throw an exception
        $this->filesResourceMock->expects($this->once())
            ->method('update')
            ->willThrowException(new Exception('API Error on update'));

        $this->adapter->write('errorfile.txt', 'error content', new Config());
    }

    // Tests for read
    public function testReadSuccessfullyReadsFile(): void
    {
        $path = 'readable.txt';
        $fileId = 'readableFileId';
        $expectedContents = 'File content';

        // Mock _getFileIdByPath to return the fileId
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->with($this->callback(fn($params) => $params['q'] === "name = '".basename($path)."' and 'appDataFolder' in parents and trashed = false"))
            ->willReturn($fileListMock);

        // Mock files->get to return content
        // Assuming Google_Service_Drive_Resource_Files::get returns StreamInterface for 'alt=media'
        $streamMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $streamMock->method('getContents')->willReturn($expectedContents);

        $this->filesResourceMock->expects($this->once())
            ->method('get')
            ->with($fileId, ['alt' => 'media'])
            ->willReturn($streamMock); // Or directly $expectedContents if not using StreamInterface

        $contents = $this->adapter->read($path);
        $this->assertEquals($expectedContents, $contents);
    }

    public function testReadThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(\League\Flysystem\UnableToReadFile::class);
        $this->expectExceptionMessage("File not found.");

        $path = 'nonexistent.txt';

        // Mock _getFileIdByPath to return null
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->willReturn($fileListMock);

        $this->adapter->read($path);
    }

    public function testReadHandlesApiError(): void
    {
        $this->expectException(\League\Flysystem\UnableToReadFile::class);
        $this->expectExceptionMessage("Could not read file: API Error on get");

        $path = 'errorfile.txt';
        $fileId = 'errorFileId';

        // Mock _getFileIdByPath to return the fileId
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->willReturn($fileListMock);

        // Mock files->get to throw an exception
        $this->filesResourceMock->expects($this->once())
            ->method('get')
            ->with($fileId, ['alt' => 'media'])
            ->willThrowException(new Exception('API Error on get'));

        $this->adapter->read($path);
    }

    // Tests for delete
    public function testDeleteSuccessfullyDeletesFile(): void
    {
        $path = 'deletable.txt';
        $fileId = 'deletableFileId';

        // Mock _getFileIdByPath to return the fileId
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->willReturn($fileListMock);

        // Expect files->delete to be called
        $this->filesResourceMock->expects($this->once())
            ->method('delete')
            ->with($fileId);

        $this->adapter->delete($path);
    }

    public function testDeleteDoesNothingForNonExistentFile(): void
    {
        $path = 'nonexistent_for_delete.txt';

        // Mock _getFileIdByPath to return null
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath
            ->method('listFiles')
            ->willReturn($fileListMock);

        // Expect files->delete NOT to be called
        $this->filesResourceMock->expects($this->never())
            ->method('delete');

        $this->adapter->delete($path);
    }

    public function testDeleteHandlesApiError(): void
    {
        $this->expectException(\League\Flysystem\UnableToDeleteFile::class);
        $this->expectExceptionMessage("Could not delete file: API Error on delete");

        $path = 'error_delete.txt';
        $fileId = 'errorDeleteFileId';

        // Mock _getFileIdByPath to return the fileId
        $mockFileForList = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId]);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$mockFileForList]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath for delete operation
            ->method('listFiles')
            ->willReturn($fileListMock);

        // Mock files->delete to throw an exception
        $this->filesResourceMock->expects($this->once())
            ->method('delete')
            ->with($fileId)
            ->willThrowException(new Exception('API Error on delete'));

        $this->adapter->delete($path);
    }

    // Tests for createDirectory
    public function testCreateDirectorySuccessfullyCreatesNewDirectory(): void
    {
        $path = 'new_dir';

        // Mock directoryExists to return false initially
        $this->filesResourceMock->expects($this->exactly(2)) // Once for directoryExists, once for _getOrCreateParentFolderId (implicitly via _getParentIdAndBaseName)
            ->method('listFiles')
            ->with($this->callback(function ($params) use ($path) {
                // This is for _getFileIdByPath called by directoryExists, then by _getOrCreateParentFolderId
                return $params['q'] === "name = '$path' and 'appDataFolder' in parents and trashed = false" || // For directoryExists
                       $params['q'] === "name = '$path' and 'appDataFolder' in parents and trashed = false"; // For _getParentIdAndBaseName -> _getOrCreateParentFolderId if root
            }))
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        // Mock files->create for the directory
        $this->filesResourceMock->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function (Google_Service_Drive_DriveFile $driveFile) use ($path) {
                    return $driveFile->getName() === $path &&
                           $driveFile->getMimeType() === 'application/vnd.google-apps.folder' &&
                           $driveFile->getParents() === ['appDataFolder'];
                }),
                ['fields' => 'id']
            )
            ->willReturn($this->createMock(Google_Service_Drive_DriveFile::class));

        $this->adapter->createDirectory($path, new Config());
    }

    public function testCreateDirectorySucceedsIfDirectoryAlreadyExists(): void
    {
        $path = 'existing_dir';

        // Mock directoryExists to return true
        $existingFolderMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => 'existingFolderId', 'getMimeType' => 'application/vnd.google-apps.folder']);
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$existingFolderMock]]);

        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath in directoryExists
            ->method('listFiles')
            ->willReturn($fileListMock);

        $this->driveServiceMock->files = $this->filesResourceMock; // Ensure the mock is used for the get call too
        $this->filesResourceMock->expects($this->once()) // For the get call in directoryExists
            ->method('get')
            ->with('existingFolderId', ['fields' => 'mimeType'])
            ->willReturn($existingFolderMock);

        // Ensure files->create is NOT called
        $this->filesResourceMock->expects($this->never())->method('create');

        $this->adapter->createDirectory($path, new Config());
    }

    public function testCreateDirectoryHandlesApiError(): void
    {
        $path = 'error_dir';
        $this->expectException(\League\Flysystem\UnableToCreateDirectory::class);
        $this->expectExceptionMessage("Failed to create directory at {$path} due to: API Error on create directory");

        // Mock directoryExists to return false
         $this->filesResourceMock->method('listFiles')
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        // Mock files->create to throw an exception
        $this->filesResourceMock->expects($this->once())
            ->method('create')
            ->willThrowException(new Exception('API Error on create directory'));

        $this->adapter->createDirectory($path, new Config());
    }

    public function testCreateNestedDirectorySuccessfully(): void
    {
        $path = "parent/new_nested_dir";

        // Simplified mocking: Assume _getFileIdByPath and _getOrCreateParentFolderId work as tested elsewhere or with simpler mocks for this specific test.
        // We are primarily testing the sequence for createDirectory itself.

        // 1. directoryExists('parent/new_nested_dir') check:
        //    - _getFileIdByPath('parent/new_nested_dir') is called.
        //      - listFiles for 'parent' -> returns [].
        //    - directoryExists returns false.
        $this->filesResourceMock
            ->method('listFiles') // This will be called multiple times by helpers
            ->willReturnCallback(function(array $optParams) {
                 // For _getFileIdByPath('parent/new_nested_dir') for 'parent' folder part
                if ($optParams['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'parent' and 'appDataFolder' in parents and trashed = false") {
                    return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
                }
                // For _getOrCreateParentFolderId for 'parent' (called by _getParentIdAndBaseName)
                // It first checks if 'parent' exists (same query as above), then creates it.
                // For _getFileIdByPath('parent/new_nested_dir') for 'new_nested_dir' part (after 'parent' is "created")
                if ($optParams['q'] === "name = 'new_nested_dir' and 'createdParentFolderId' in parents and trashed = false") {
                     return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
                }
                return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
            });

        // 2. _getParentIdAndBaseName('parent/new_nested_dir')
        //    - _getOrCreateParentFolderId('parent/')
        //      - listFiles for 'parent' (mocked above to return [])
        //      - files->create for 'parent'
        $createdParentFolderMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => 'createdParentFolderId']);

        // 3. files->create for 'new_nested_dir'
        $createdNestedDirMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => 'createdNestedDirId']);

        $this->filesResourceMock->expects($this->exactly(2)) // Create 'parent', then 'new_nested_dir'
            ->method('create')
            ->withConsecutive(
                [$this->callback(function (Google_Service_Drive_DriveFile $driveFile) { // For 'parent'
                    return $driveFile->getName() === 'parent' && $driveFile->getParents() === ['appDataFolder'];
                }), $this->anything()],
                [$this->callback(function (Google_Service_Drive_DriveFile $driveFile) { // For 'new_nested_dir'
                    return $driveFile->getName() === 'new_nested_dir' && $driveFile->getParents() === ['createdParentFolderId'];
                }), $this->anything()]
            )
            ->willReturnOnConsecutiveCalls($createdParentFolderMock, $createdNestedDirMock);

        $this->adapter->createDirectory($path, new Config());
    }

    // Tests for deleteDirectory
    public function testDeleteDirectorySuccessfullyDeletesEmptyDirectory(): void
    {
        $path = 'empty_dir';
        $folderId = 'emptyDirId';

        // Mock _getFileIdByPath to return folderId
        $folderFileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $folderId])]]);
        $this->filesResourceMock->expects($this->once())
            ->method('listFiles')
            ->with($this->callback(fn($p) => $p['q'] === "name = '$path' and 'appDataFolder' in parents and trashed = false"))
            ->willReturn($folderFileListMock);

        // Mock files->get to confirm it's a folder
        $folderDetailsMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getMimeType' => 'application/vnd.google-apps.folder', 'getId' => $folderId]);
        $this->filesResourceMock->expects($this->once())
            ->method('get')
            ->with($folderId, ['fields' => 'mimeType, id'])
            ->willReturn($folderDetailsMock);

        // Mock files->delete to succeed
        $this->filesResourceMock->expects($this->once())
            ->method('delete')
            ->with($folderId);

        $this->adapter->deleteDirectory($path);
    }

    public function testDeleteDirectoryDoesNothingForNonExistentDirectory(): void
    {
        $path = 'non_existent_dir';
        // Mock _getFileIdByPath to return null
        $this->filesResourceMock->expects($this->once())
            ->method('listFiles')
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        $this->filesResourceMock->expects($this->never())->method('get');
        $this->filesResourceMock->expects($this->never())->method('delete');

        $this->adapter->deleteDirectory($path);
    }

    public function testDeleteDirectoryThrowsExceptionIfNotADirectory(): void
    {
        $path = 'file_ masquerading_as_dir.txt';
        $fileId = 'fileAsDirId';
        $this->expectException(\League\Flysystem\UnableToDeleteDirectory::class);
        $this->expectExceptionMessage("Path is not a directory.");

        // Mock _getFileIdByPath returning an ID
        $fileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $fileId])]]);
        $this->filesResourceMock->method('listFiles')->willReturn($fileListMock);

        // Mock files->get to return a non-folder MIME type
        $fileDetailsMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getMimeType' => 'text/plain', 'getId' => $fileId]);
        $this->filesResourceMock->method('get')->with($fileId, ['fields' => 'mimeType, id'])->willReturn($fileDetailsMock);

        $this->filesResourceMock->expects($this->never())->method('delete');
        $this->adapter->deleteDirectory($path);
    }

    public function testDeleteDirectoryThrowsExceptionForNonEmptyDirectory(): void
    {
        $path = 'non_empty_dir';
        $folderId = 'nonEmptyDirId';
        $this->expectException(\League\Flysystem\UnableToDeleteDirectory::class);
        $this->expectExceptionMessage("Directory is not empty.");

        // Mock _getFileIdByPath
        $folderFileListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getId' => $folderId])]]);
        $this->filesResourceMock->method('listFiles')->willReturn($folderFileListMock);

        // Mock files->get to confirm it's a folder
        $folderDetailsMock = $this->createConfiguredMock(Google_Service_Drive_DriveFile::class, ['getMimeType' => 'application/vnd.google-apps.folder', 'getId' => $folderId]);
        $this->filesResourceMock->method('get')->willReturn($folderDetailsMock);

        // Mock files->delete to throw a Google_Service_Exception indicating folder not empty
        $googleException = new \Google\Service\Exception("Folder not empty", 400);
        $googleException->setErrors([['reason' => 'folderNotEmpty']]);
        $this->filesResourceMock->expects($this->once())
            ->method('delete')
            ->with($folderId)
            ->willThrowException($googleException);

        $this->adapter->deleteDirectory($path);
    }

    public function testDeleteDirectoryHandlesGenericApiError(): void
    {
        $path = 'dir_with_generic_error';
        $folderId = 'dirErrorId';
        $this->expectException(\League\Flysystem\UnableToDeleteDirectory::class);
        // The message might vary depending on the caught exception's message.
        // We are checking that it's wrapped in UnableToDeleteDirectory.

        // Mock for _getFileDetails -> _getFileIdByPath (true for folder)
        $fileListForGetFileId = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$this->createDriveFileMock($folderId, basename($path), 'application/vnd.google-apps.folder')]]);
        $this->filesResourceMock->method('listFiles')
            ->with($this->callback(fn($p) => $p['q'] === "name = '".basename($path)."' and 'appDataFolder' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'"))
            ->willReturn($fileListForGetFileId);

        // Mock for the get call in deleteDirectory to confirm mimeType
        $folderDetailsMock = $this->createDriveFileMock($folderId, basename($path), 'application/vnd.google-apps.folder');
        $this->filesResourceMock->method('get')
            ->with($folderId, ['fields' => 'mimeType, id'])
            ->willReturn($folderDetailsMock);

        $this->filesResourceMock->expects($this->once())
            ->method('delete')
            ->with($folderId)
            ->willThrowException(new Exception('Generic API delete error'));

        $this->adapter->deleteDirectory($path);
    }

    // Tests for listContents
    protected function createDriveFileMock(string $id, string $name, string $mimeType, ?string $modifiedTime = '2024-01-01T12:00:00Z', ?int $size = 100, ?array $parents = ['appDataFolder']): MockObject
    {
        $mock = $this->createMock(Google_Service_Drive_DriveFile::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getName')->willReturn($name);
        $mock->method('getMimeType')->willReturn($mimeType);
        $mock->method('getModifiedTime')->willReturn($modifiedTime);
        $mock->method('getParents')->willReturn($parents); // Mock parents
        if ($mimeType !== 'application/vnd.google-apps.folder') {
            $mock->method('getSize')->willReturn($size);
        }
        return $mock;
    }

    public function testListContentsShallow(): void
    {
        $dirPath = 'test_dir';
        $dirId = 'dirId123';

        // Mock _getFileIdByPath for the directory (looking for folder type)
        $dirObjectForListFiles = $this->createDriveFileMock($dirId, basename($dirPath), 'application/vnd.google-apps.folder');
        $dirFileList = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$dirObjectForListFiles]]);
        $this->filesResourceMock->expects($this->once()) // For _getFileIdByPath($dirPath, true)
            ->method('listFiles')
            ->with($this->callback(fn($p) => $p['q'] === "name = '$dirPath' and 'appDataFolder' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'"))
            ->willReturn($dirFileList);

        // Mock 'get' for mimeType check on $dirId in listContents
        $this->filesResourceMock->expects($this->once())
            ->method('get')
            ->with($dirId, $this->anything())
            ->willReturn($this->createDriveFileMock($dirId, basename($dirPath), 'application/vnd.google-apps.folder'));


        // Mock listFiles for the contents of the directory
        $file1 = $this->createDriveFileMock('file1', 'file1.txt', 'text/plain', '2024-01-01T12:00:00Z', 100, [$dirId]);
        $folder1 = $this->createDriveFileMock('folder1', 'subfolder', 'application/vnd.google-apps.folder', '2024-01-01T12:00:00Z', null, [$dirId]);

        $contentsListMock = $this->createMock(Google_Service_Drive_FileList::class);
        $contentsListMock->method('getFiles')->willReturn([$file1, $folder1]);
        $contentsListMock->method('getNextPageToken')->willReturn(null);

        $this->filesResourceMock->expects($this->once()) // For listFiles itself to get contents of $dirPath
            ->method('listFiles')
            ->with($this->callback(function ($params) use ($dirId) {
                return $params['q'] === "'$dirId' in parents and trashed = false";
            }))
            ->willReturn($contentsListMock);

        $results = iterator_to_array($this->adapter->listContents($dirPath, false));

        $this->assertCount(2, $results);
        $this->assertEquals($dirPath . '/file1.txt', $results[0]->path());
        $this->assertTrue($results[0]->isFile());
        $this->assertEquals($dirPath . '/subfolder', $results[1]->path());
        $this->assertTrue($results[1]->isDir());
    }

    public function testListContentsDeep(): void
    {
        // Root directory listing
        $file1 = $this->createDriveFileMock('file1', 'rootfile.txt', 'text/plain', '2024-01-01T12:00:00Z', 100, ['appDataFolder']);
        $folderA = $this->createDriveFileMock('folderA_id', 'FolderA', 'application/vnd.google-apps.folder', '2024-01-01T12:00:00Z', null, ['appDataFolder']);

        $rootContentsList = $this->createMock(Google_Service_Drive_FileList::class);
        $rootContentsList->method('getFiles')->willReturn([$file1, $folderA]);
        $rootContentsList->method('getNextPageToken')->willReturn(null);

        // FolderA contents listing
        $fileA1 = $this->createDriveFileMock('fileA1_id', 'fileA1.txt', 'text/plain', '2024-01-01T12:00:00Z', 100, ['folderA_id']);
        $folderAContentsList = $this->createMock(Google_Service_Drive_FileList::class);
        $folderAContentsList->method('getFiles')->willReturn([$fileA1]);
        $folderAContentsList->method('getNextPageToken')->willReturn(null);

        $this->filesResourceMock->expects($this->exactly(3))
             ->method('listFiles')
             ->willReturnCallback(function(array $params) use ($rootContentsList, $folderAContentsList, $folderA) {
                 if ($params['q'] === "'appDataFolder' in parents and trashed = false") {
                     return $rootContentsList;
                 }
                 // This call is for _getFileIdByPath('FolderA', 'appDataFolder', true) during recursive step
                 if ($params['q'] === "name = 'FolderA' and 'appDataFolder' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'") {
                     return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$folderA]]);
                 }
                 if ($params['q'] === "'" . $folderA->getId() . "' in parents and trashed = false") {
                     return $folderAContentsList;
                 }
                 // Fallback for any other unspecific listFiles call during the test, ensures no error if mocks are slightly off.
                 return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
             });

        // Mock 'get' for mimeType check for 'FolderA' when listContents('FolderA', ...) is called.
        $this->filesResourceMock->expects($this->once())
            ->method('get')
            ->with($folderA->getId(), $this->anything()) // For the mimeType check inside listContents for 'FolderA'
            ->willReturn($this->createDriveFileMock($folderA->getId(), 'FolderA', 'application/vnd.google-apps.folder'));


        $results = iterator_to_array($this->adapter->listContents('', true)); // List root deeply

        $this->assertCount(3, $results); // rootfile.txt, FolderA, FolderA/fileA1.txt
        $paths = array_map(fn($attr) => $attr->path(), $results);
        $this->assertContains('rootfile.txt', $paths);
        $this->assertContains('FolderA', $paths);
        $this->assertContains('FolderA/fileA1.txt', $paths);
    }

    public function testListContentsReturnsEmptyArrayForNonExistentDirectory(): void
    {
        $this->filesResourceMock->expects($this->once())
            ->method('listFiles') // For _getFileIdByPath, expecting folder type
            ->with($this->callback(fn($p) => $p['q'] === "name = 'non_existent_dir' and 'appDataFolder' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'"))
            ->willReturn($this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]));

        $results = iterator_to_array($this->adapter->listContents('non_existent_dir', false));
        $this->assertEmpty($results);
    }

    public function testListContentsHandlesPagination(): void
    {
        $file1 = $this->createDriveFileMock('file1', 'page1.txt', 'text/plain', '2024-01-01T12:00:00Z', 100, ['appDataFolder']);
        $page1List = $this->createMock(Google_Service_Drive_FileList::class);
        $page1List->method('getFiles')->willReturn([$file1]);
        $page1List->method('getNextPageToken')->willReturn('nextPageToken123');

        $file2 = $this->createDriveFileMock('file2', 'page2.txt', 'text/plain', '2024-01-01T12:00:00Z', 100, ['appDataFolder']);
        $page2List = $this->createMock(Google_Service_Drive_FileList::class);
        $page2List->method('getFiles')->willReturn([$file2]);
        $page2List->method('getNextPageToken')->willReturn(null); // End of list

        $this->filesResourceMock->expects($this->exactly(2)) // For listing contents of root
            ->method('listFiles')
            ->withConsecutive(
                [$this->callback(fn($p) => $p['q'] === "'appDataFolder' in parents and trashed = false" && ($p['pageToken'] ?? null) === null)],
                [$this->callback(fn($p) => $p['q'] === "'appDataFolder' in parents and trashed = false" && $p['pageToken'] === 'nextPageToken123')]
            )
            ->willReturnOnConsecutiveCalls($page1List, $page2List);

        $results = iterator_to_array($this->adapter->listContents('', false));
        $this->assertCount(2, $results);
        $this->assertEquals('page1.txt', $results[0]->path());
        $this->assertEquals('page2.txt', $results[1]->path());
    }

    public function testListContentsHandlesApiError(): void
    {
        $this->expectException(\League\Flysystem\UnableToListContents::class);
        $this->filesResourceMock->expects($this->once()) // For listing contents of root
            ->method('listFiles')
            ->with($this->callback(fn($p) => $p['q'] === "'appDataFolder' in parents and trashed = false")) // This now correctly matches the implemented query
            ->willThrowException(new Exception('API list error'));

        iterator_to_array($this->adapter->listContents('', false));
    }

    // Tests for move
    public function testMoveFileSuccessfully(): void
    {
        $source = 'source.txt';
        $destination = 'dest/moved.txt';
        $sourceId = 'sourceId';
        $originalParentId = 'appDataFolder';
        $destParentId = 'destParentId';

        // Mock for _getFileDetails($source)
        $sourceFileMock = $this->createDriveFileMock($sourceId, basename($source), 'text/plain', '2024-01-01T00:00:00Z', 100, [$originalParentId]);
        $sourceDetailsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$sourceFileMock]]);

        // Mock for _getFileIdByPath($destination) - destination does not exist
        $destNonExistListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);

        // Mock for _getOrCreateParentFolderId for 'dest/'
        $destParentFolderMock = $this->createDriveFileMock($destParentId, 'dest', 'application/vnd.google-apps.folder');
        $destParentFolderListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$destParentFolderMock]]);

        $this->filesResourceMock->expects($this->any()) // Use any() due to complexity of matching all listFiles calls
            ->method('listFiles')
            ->willReturnCallback(function(array $params) use ($source, $destination, $sourceDetailsListMock, $destNonExistListMock, $destParentFolderListMock){
                if ($params['q'] === "name = '".basename($source)."' and 'appDataFolder' in parents and trashed = false") { // Call from _getFileDetails for source
                    return $sourceDetailsListMock;
                }
                // Call from _getFileIdByPath for destination (checking if it exists)
                // The parent ID in this query ('$destParentId') is determined by _getOrCreateParentFolderId
                if (str_contains($params['q'], "name = '".basename($destination)."'") && str_contains($params['q'], "in parents and trashed = false")) {
                     return $destNonExistListMock;
                }
                // Call from _getOrCreateParentFolderId (part of _getParentIdAndBaseName for destination) for 'dest'
                if ($params['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'dest' and 'appDataFolder' in parents and trashed = false") {
                    return $destParentFolderListMock;
                }
                return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
            });

        $this->filesResourceMock->expects($this->once())
            ->method('update')
            ->with(
                $sourceId,
                $this->callback(fn($driveFile) => $driveFile->getName() === 'moved.txt'),
                $this->callback(fn($params) => $params['addParents'] === $destParentId && $params['removeParents'] === $originalParentId)
            )
            ->willReturn($this->createMock(Google_Service_Drive_DriveFile::class));

        $this->adapter->move($source, $destination, new Config());
    }

     public function testMoveFailsIfDestinationExists(): void
    {
        $source = 'source.txt';
        $destination = 'existing.txt';
        $sourceId = 'sourceId';
        $originalParentId = 'appDataFolder';

        $this->expectException(\League\Flysystem\UnableToMoveFile::class);
        $this->expectExceptionMessage("Unable to move file from $source to $destination because the destination already exists.");

        // Mock for _getFileDetails($source)
        $sourceFileMock = $this->createDriveFileMock($sourceId, basename($source), 'text/plain', '2024-01-01T00:00:00Z', 100, [$originalParentId]);
        $sourceDetailsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$sourceFileMock]]);

        // Mock for _getFileIdByPath($destination, 'appDataFolder', false) - destination exists
        $destFileMock = $this->createDriveFileMock('destId', basename($destination), 'text/plain');
        $destExistsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$destFileMock]]);

        $this->filesResourceMock->expects($this->exactly(2))
            ->method('listFiles')
            ->willReturnCallback(function(array $params) use ($source, $destination, $sourceDetailsListMock, $destExistsListMock){
                 if ($params['q'] === "name = '".basename($source)."' and 'appDataFolder' in parents and trashed = false") { // from _getFileDetails for source
                     return $sourceDetailsListMock;
                 }
                 // from _getFileIdByPath for destination (false for not a folder)
                 if ($params['q'] === "name = '".basename($destination)."' and 'appDataFolder' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'") {
                     return $destExistsListMock;
                 }
                 return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
            });

        $this->adapter->move($source, $destination, new Config());
    }


    // Tests for copy
    public function testCopyFileSuccessfully(): void
    {
        $source = 'source.txt';
        $destination = 'dest/copied.txt';
        $sourceId = 'sourceId';
        $destParentId = 'destParentId';

        // Mock for _getFileDetails($source)
        $sourceFileMock = $this->createDriveFileMock($sourceId, basename($source), 'text/plain');
        $sourceDetailsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$sourceFileMock]]);

        $this->filesResourceMock->expects($this->atLeastOnce())
            ->method('listFiles')
            ->willReturnCallback(function(array $params) use ($source, $destination, $sourceDetailsListMock, $destParentId){
                if ($params['q'] === "name = '".basename($source)."' and 'appDataFolder' in parents and trashed = false") { // _getFileDetails for source
                    return $sourceDetailsListMock;
                }
                // _getFileIdByPath for destination file (false for not a folder)
                if (str_contains($params['q'], "name = '".basename($destination)."'") && str_contains($params['q'], "mimeType != 'application/vnd.google-apps.folder'")) {
                    return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]); // Dest file does not exist
                }
                 // _getOrCreateParentFolderId for 'dest/' (true for folder)
                if ($params['q'] === "mimeType = 'application/vnd.google-apps.folder' and name = 'dest' and 'appDataFolder' in parents and trashed = false") {
                    return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$this->createDriveFileMock($destParentId, 'dest', 'application/vnd.google-apps.folder')]]);
                }
                return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
            });

        $this->filesResourceMock->expects($this->once())
            ->method('copy')
            ->with(
                $sourceId,
                $this->callback(function(Google_Service_Drive_DriveFile $driveFile) use ($destParentId) {
                    return $driveFile->getName() === 'copied.txt' && $driveFile->getParents() === [$destParentId];
                })
            )
            ->willReturn($this->createMock(Google_Service_Drive_DriveFile::class));

        $this->adapter->copy($source, $destination, new Config());
    }

    public function testCopyFailsIfDestinationExists(): void
    {
        $source = 'source.txt';
        $destination = 'existing.txt';
        $sourceId = 'sourceId';

        $this->expectException(\League\Flysystem\UnableToCopyFile::class);
        $this->expectExceptionMessage("Unable to copy file from $source to $destination because the destination already exists.");

        $sourceFileMock = $this->createDriveFileMock($sourceId, basename($source), 'text/plain');
        $sourceDetailsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$sourceFileMock]]);

        $destFileMock = $this->createDriveFileMock('destId', basename($destination), 'text/plain');
        $destExistsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$destFileMock]]);

        $this->filesResourceMock->expects($this->exactly(2))
             ->method('listFiles')
             ->willReturnCallback(function(array $params) use ($source, $destination, $sourceDetailsListMock, $destExistsListMock){
                 if ($params['q'] === "name = '".basename($source)."' and 'appDataFolder' in parents and trashed = false") { // _getFileDetails for source
                     return $sourceDetailsListMock;
                 }
                  // _getFileIdByPath for destination (false for not a folder)
                 if ($params['q'] === "name = '".basename($destination)."' and 'appDataFolder' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'") {
                     return $destExistsListMock;
                 }
                 return $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
             });

        $this->adapter->copy($source, $destination, new Config());
    }

     public function testCopyFailsForDirectory(): void
    {
        $source = 'sourceFolder';
        $destination = 'destFolder';
        $sourceId = 'sourceId';

        $this->expectException(\League\Flysystem\UnableToCopyFile::class);
        $this->expectExceptionMessage("Copying directories is not supported.");

        $sourceFileMock = $this->createDriveFileMock($sourceId, $source, 'application/vnd.google-apps.folder');
        $sourceDetailsListMock = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$sourceFileMock]]);
        // This will be called by _getFileDetails
        $this->filesResourceMock->method('listFiles')
            ->with($this->callback(fn($p) => $p['q'] === "name = '$source' and 'appDataFolder' in parents and trashed = false"))
            ->willReturn($sourceDetailsListMock);

        $this->adapter->copy($source, $destination, new Config());
    }

    // Tests for lastModified, mimeType, visibility, setVisibility
    public function testLastModifiedReturnsCorrectTimestamp(): void
    {
        $path = 'file.txt';
        $fileId = 'fileId';
        $isoTimestamp = '2023-10-27T10:30:00.000Z';
        $unixTimestamp = strtotime($isoTimestamp);

        $fileMock = $this->createDriveFileMock($fileId, basename($path), 'text/plain', $isoTimestamp);
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$fileMock]]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult); // For _getFileDetails

        $attributes = $this->adapter->lastModified($path);
        $this->assertEquals($unixTimestamp, $attributes->lastModified());
    }

    public function testMimeTypeReturnsCorrectType(): void
    {
        $path = 'file.txt';
        $fileId = 'fileId';
        $mime = 'text/plain';

        $fileMock = $this->createDriveFileMock($fileId, basename($path), $mime);
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$fileMock]]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult); // For _getFileDetails

        $attributes = $this->adapter->mimeType($path);
        $this->assertEquals($mime, $attributes->mimeType());
    }

    public function testMimeTypeForFolderReturnsCorrectType(): void
    {
        $path = 'folder';
        $fileId = 'folderId';
        $mime = 'application/vnd.google-apps.folder';

        $fileMock = $this->createDriveFileMock($fileId, basename($path), $mime);
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$fileMock]]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult); // For _getFileDetails

        $attributes = $this->adapter->mimeType($path);
        $this->assertEquals($mime, $attributes->mimeType());
    }


    public function testVisibilityReturnsPrivate(): void
    {
        $path = 'file.txt';
        $fileId = 'fileId';

        // Mock for _getFileIdByPath (doesn't need to be too specific for this test, just needs to return something)
        $fileMock = $this->createDriveFileMock($fileId, basename($path), 'text/plain');
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$fileMock]]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult);

        $attributes = $this->adapter->visibility($path);
        $this->assertEquals(\League\Flysystem\Visibility::PRIVATE, $attributes->visibility());
    }

    public function testSetVisibilityIsNoOpAndSucceedsForExistingFile(): void
    {
        $path = 'file.txt';
        $fileId = 'fileId';

        // Mock for _getFileIdByPath to indicate file exists
        $fileMock = $this->createDriveFileMock($fileId, basename($path), 'text/plain');
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => [$fileMock]]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult);

        $this->filesResourceMock->expects($this->never())->method('update'); // No API call should be made
        $this->adapter->setVisibility($path, \League\Flysystem\Visibility::PUBLIC);
        // No exception means success
    }

    public function testSetVisibilityThrowsExceptionForNonExistingFile(): void
    {
        $path = 'nonexistent.txt';
        $this->expectException(\League\Flysystem\UnableToSetVisibility::class);

        // Mock for _getFileIdByPath to indicate file does not exist
        $listResult = $this->createConfiguredMock(Google_Service_Drive_FileList::class, ['getFiles' => []]);
        $this->filesResourceMock->method('listFiles')->willReturn($listResult);

        $this->adapter->setVisibility($path, \League\Flysystem\Visibility::PRIVATE);
    }
}
