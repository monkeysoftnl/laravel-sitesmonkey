<?php

namespace MonkeySoft\SitesMonkey\Http\Controllers\Api;

class StatusController extends \MonkeySoft\SitesMonkey\Http\Controllers\Controller
{
    /**
     * Save the status message.
     */
    public function getStatus(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $mysqlVersion = null;
        $databaseConnection = config('database.default');
        $databaseConfig = config("database.connections.{$databaseConnection}");
        if ($databaseConnection === 'mysql' || $databaseConnection === 'mariadb') {
            try {
                $pdo = new \PDO(
                    "mysql:host={$databaseConfig['host']};dbname={$databaseConfig['database']}",
                    $databaseConfig['username'],
                    $databaseConfig['password']
                );
                $mysqlVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\PDOException $e) {
                // Handle the exception if needed
            }
        }

        return response()->json([
            'website_name' => config('app.name', 'Unknown Application'),
            'website_description' => config('app.description', 'No description provided.'),
            'website_app_version' => config('app.version', '0.0.0'),
            'website_php_version' => phpversion(),
            'website_laravel_version' => app()->version(),
            'website_database_driver' => $databaseConfig['driver'],
            'website_database_version' => $mysqlVersion,
        ]);
    }
}
