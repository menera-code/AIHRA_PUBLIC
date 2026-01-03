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
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
            'Access-Control-Allow-Credentials' => 'true'
        ];
        
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200)->withHeaders($headers);
        }
        
        try {
            $credentials = $this->getDialogflowCredentials();
            
            if (!$credentials) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'intents_synced' => 2,
                        'intents' => $this->getMockIntents(),
                        'is_mock_data' => true
                    ]
                ])->withHeaders($headers);
            }
            
            $projectId = env('DIALOGFLOW_PROJECT_ID', 'aihra-472311');
            
            $client = new IntentsClient([
                'credentials' => $credentials,
                'projectId' => $projectId
            ]);

            $parent = "projects/{$projectId}/agent";
            $requestObj = new ListIntentsRequest();
            $requestObj->setParent($parent);
            // Don't set language code - let it use default
            $requestObj->setPageSize(100);
            
            $response = $client->listIntents($requestObj);
            $intents = [];
            $counter = 1;
            
            foreach ($response->iterateAllElements() as $intent) {
                // Count training phrases (simplified)
                $trainingPhrases = $intent->getTrainingPhrases();
                $trainingCount = 0;
                
                if ($trainingPhrases) {
                    $trainingCount = iterator_count($trainingPhrases);
                }
                
                // Count responses (simplified)
                $messages = $intent->getMessages();
                $responseCount = 0;
                
                if ($messages) {
                    $responseCount = iterator_count($messages);
                }
                
                // Determine status
                $status = 'Regular';
                if (method_exists($intent, 'getIsFallback') && $intent->getIsFallback()) {
                    $status = 'Fallback';
                }
                
                // Get display name
                $displayName = $intent->getDisplayName();
                
                // Create simple text representations
                $trainingText = $trainingCount > 0 ? "Has {$trainingCount} training phrases" : "No training phrases";
                $responseText = $responseCount > 0 ? "Has {$responseCount} responses" : "No responses";
                
                $intents[] = [
                    'id' => $counter++,
                    'intent_name' => $intent->getName(),
                    'display_name' => $displayName,
                    'training_phrases_text' => $trainingText,
                    'training_phrases_count' => $trainingCount,
                    'responses_text' => $responseText,
                    'responses_count' => $responseCount,
                    'status' => $status,
                    'last_modified' => date('Y-m-d H:i:s'),
                    'actions' => 'Edit | Delete'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => count($intents),
                    'intents' => $intents,
                    'is_mock_data' => false,
                    'message' => 'Successfully fetched ' . count($intents) . ' intents'
                ]
            ])->withHeaders($headers);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dialogflow sync failed: ' . $e->getMessage(),
                'data' => [
                    'intents_synced' => 0,
                    'intents' => $this->getMockIntents(),
                    'is_mock_data' => true
                ]
            ], 500)->withHeaders($headers);
        }
    }
    
    private function getMockIntents()
    {
        return [
            [
                'id' => 1,
                'intent_name' => 'projects/aihra-472311/agent/intents/0008b207-67fa-42cf-abd1-db1fbdbc2fc8',
                'display_name' => 'EmployeeDevelopment_StudyGrantC_016',
                'training_phrases_text' => '"How do I apply for study grant?"',
                'training_phrases_count' => 5,
                'responses_text' => '"Study grants are available..."',
                'responses_count' => 2,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ],
            [
                'id' => 2,
                'intent_name' => 'projects/aihra-472311/agent/intents/002f487e-ab1f-4757-99c4-d74b1ad0aa94',
                'display_name' => 'EmployeeDevelopment_Seminars_003',
                'training_phrases_text' => '"What seminars are available?"',
                'training_phrases_count' => 3,
                'responses_text' => '"Seminars are scheduled..."',
                'responses_count' => 1,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ]
        ];
    }
    
    private function getDialogflowCredentials()
    {
        $fullJson = env('DIALOGFLOW_CREDENTIALS_JSON');
        
        if ($fullJson) {
            $credentials = json_decode($fullJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $credentials;
            }
        }
        
        $privateKey = env('DIALOGFLOW_PRIVATE_KEY');
        $clientEmail = env('DIALOGFLOW_CLIENT_EMAIL');
        
        if ($privateKey && $clientEmail) {
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
        
        $filePath = storage_path('app/dialogflow.json');
        if (file_exists($filePath)) {
            return $filePath;
        }
        
        return null;
    }
    
    private function formatPrivateKey($privateKey)
    {
        if (empty($privateKey)) {
            return '';
        }
        
        $key = str_replace(['\n', '\\n'], "\n", $privateKey);
        
        if (!str_contains($key, 'BEGIN PRIVATE KEY')) {
            $key = "-----BEGIN PRIVATE KEY-----\n" . $key . "\n-----END PRIVATE KEY-----\n";
        }
        
        return $key;
    }
}
