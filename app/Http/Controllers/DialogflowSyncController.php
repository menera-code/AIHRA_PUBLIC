<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DialogflowSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        // Set CORS headers
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
        ];
        
        try {
            // Get credentials
            $credentials = $this->getDialogflowCredentials();
            
            // If no credentials, return mock data
            if (!$credentials) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'intents_synced' => 3,
                        'intents' => [
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-1',
                                'display_name' => 'Welcome Intent',
                                'training_phrases' => 5
                            ],
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-2', 
                                'display_name' => 'FAQ Intent',
                                'training_phrases' => 8
                            ],
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-3',
                                'display_name' => 'Fallback Intent',
                                'training_phrases' => 1
                            ]
                        ],
                        'is_mock_data' => true,
                        'message' => 'Dialogflow credentials not configured. Using mock data.'
                    ],
                    'message' => 'Using mock data - configure Dialogflow credentials'
                ])->withHeaders($headers);
            }
            
            // Get project ID
            $projectId = env('DIALOGFLOW_PROJECT_ID', 'aihra-472311');
            
            // Create Dialogflow client
            $client = new IntentsClient([
                'credentials' => $credentials,
                'projectId' => $projectId
            ]);

            // List intents
            $parent = "projects/{$projectId}/agent";
            $requestObj = new ListIntentsRequest();
            $requestObj->setParent($parent);
            
            $response = $client->listIntents($requestObj);
            $intents = [];
            
            foreach ($response->iterateAllElements() as $intent) {
                $intents[] = [
                    'id' => $intent->getName(),
                    'display_name' => $intent->getDisplayName(),
                    'training_phrases' => count($intent->getTrainingPhrases()),
                ];
            }

            // Return success with real data
            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => count($intents),
                    'intents' => $intents,
                    'is_mock_data' => false,
                    'message' => 'Successfully fetched ' . count($intents) . ' intents from Dialogflow'
                ],
                'message' => 'Sync successful'
            ])->withHeaders($headers);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'intents_synced' => 0,
                    'intents' => [],
                    'is_mock_data' => true,
                    'error' => $e->getMessage()
                ]
            ], 500)->withHeaders($headers);
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
        
        // Instead of throwing exception, return mock data
        // This allows your x10 app to work even if credentials are missing
        return null;
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
