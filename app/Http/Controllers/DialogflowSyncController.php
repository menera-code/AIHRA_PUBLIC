<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Illuminate\Http\JsonResponse;

class DialogflowSyncController extends Controller
{
    public function sync(): JsonResponse
    {
        try {
            // Get project ID
            $projectId = env('DIALOGFLOW_PROJECT_ID', 'aihra-472311');
            
            // Get credentials
            $credentials = $this->getDialogflowCredentials();
            
            // Create Dialogflow client (v2.3+ syntax)
            $client = new IntentsClient([
                'credentials' => $credentials,
                'projectId' => $projectId
            ]);

            // List intents (v2.3+ syntax)
            $parent = "projects/{$projectId}/agent";
            $request = new ListIntentsRequest();
            $request->setParent($parent);
            
            $response = $client->listIntents($request);
            $intents = [];
            
            foreach ($response->iterateAllElements() as $intent) {
                $intents[] = [
                    'id' => $intent->getName(),
                    'display_name' => $intent->getDisplayName(),
                    'training_phrases' => count($intent->getTrainingPhrases()),
                ];
            }

            return response()->json([
                'success' => true,
                'intents' => $intents,
                'count' => count($intents)
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    
    private function getDialogflowCredentials()
    {
        // Try to get full JSON first
        $fullJson = env('DIALOGFLOW_CREDENTIALS_JSON');
        
        if ($fullJson) {
            $credentials = json_decode($fullJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $credentials;
            }
        }
        
        // If no full JSON, try to build from pieces
        $privateKey = env('DIALOGFLOW_PRIVATE_KEY');
        $clientEmail = env('DIALOGFLOW_CLIENT_EMAIL');
        
        if ($privateKey && $clientEmail) {
            // Build credentials array from pieces
            return [
                'type' => 'service_account',
                'project_id' => env('DIALOGFLOW_PROJECT_ID', 'aihra-472311'),
                'private_key_id' => env('DIALOGFLOW_PRIVATE_KEY_ID', '24cbcd0644cf59089189291679ee9463f1c8c9a9'),
                'private_key' => $this->formatPrivateKey($privateKey),
                'client_email' => $clientEmail,
                'client_id' => env('DIALOGFLOW_CLIENT_ID', '116880942476929227852'),
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => env('DIALOGFLOW_CLIENT_X509_CERT_URL', 'https://www.googleapis.com/robot/v1/metadata/x509/aihra-dialogflow%40aihra-472311.iam.gserviceaccount.com')
            ];
        }
        
        // Last resort: check for file
        $filePath = storage_path('app/dialogflow.json');
        if (file_exists($filePath)) {
            return $filePath;
        }
        
        throw new \Exception('Dialogflow credentials not found. Set DIALOGFLOW_CREDENTIALS_JSON or individual credential pieces.');
    }
    
    private function formatPrivateKey($privateKey)
    {
        // Ensure the private key has proper line breaks
        $key = str_replace(['\n', '\\n'], "\n", $privateKey);
        
        // Add BEGIN/END markers if missing
        if (!str_contains($key, 'BEGIN PRIVATE KEY')) {
            $key = "-----BEGIN PRIVATE KEY-----\n" . $key . "\n-----END PRIVATE KEY-----\n";
        }
        
        return $key;
    }
}
