<?php

namespace App\Providers;

use App\Filesystem\GoogleDriveAdapter;
use Google_Client;
use Google_Service_Drive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class GoogleDriveServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Storage::extend('google', function ($app, $config) {
            $client = new Google_Client();
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);
            $client->refreshToken($config['refreshToken']);

            $service = new Google_Service_Drive($client);
            // Assuming the GoogleDriveAdapter constructor takes the service and an optional config array
            // If your adapter takes folderId directly, adjust accordingly.
            $adapter = new GoogleDriveAdapter($service);

            return new Filesystem($adapter, $config);
        });
    }
}
