<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Google\Cloud\Dialogflow\V2\AgentTypesClient;
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
                \Log::info('DialogflowSync: Using mock data (no credentials)');
                return response()->json([
                    'success' => true,
                    'data' => [
                        'intents_synced' => 2,
                        'intents' => $this->getMockIntents(),
                        'is_mock_data' => true,
                        'debug' => 'No Dialogflow credentials found'
                    ]
                ])->withHeaders($headers);
            }
            
            $projectId = env('DIALOGFLOW_PROJECT_ID', 'aihra-472311');
            
            \Log::info('DialogflowSync: Attempting to connect', [
                'project_id' => $projectId,
                'client_email' => $credentials['client_email'] ?? 'unknown'
            ]);
            
            // TEST 1: First test the connection with a simple agent request
            try {
                $client = new IntentsClient([
                    'credentials' => $credentials,
                    'projectId' => $projectId
                ]);
                
                $parent = "projects/{$projectId}/agent";
                \Log::info('DialogflowSync: Parent path: ' . $parent);
                
                // Test with a very simple request first
                $requestObj = new ListIntentsRequest();
                $requestObj->setParent($parent);
                $requestObj->setPageSize(10); // Small page size for testing
                
                \Log::info('DialogflowSync: Sending ListIntents request');
                
                // Get the response
                $response = $client->listIntents($requestObj);
                
                // Debug: Check what type of response we got
                \Log::info('DialogflowSync: Got response', [
                    'response_type' => get_class($response),
                    'has_iterator' => method_exists($response, 'iterateAllElements')
                ]);
                
                $intents = [];
                $counter = 0;
                
                // Try to iterate through intents
                if (method_exists($response, 'iterateAllElements')) {
                    foreach ($response->iterateAllElements() as $intent) {
                        $counter++;
                        \Log::info("DialogflowSync: Found intent #{$counter}", [
                            'name' => $intent->getName(),
                            'display_name' => $intent->getDisplayName()
                        ]);
                        
                        // Count training phrases
                        $trainingPhrases = $intent->getTrainingPhrases();
                        $trainingCount = 0;
                        $trainingTexts = [];
                        
                        if ($trainingPhrases) {
                            try {
                                foreach ($trainingPhrases as $phrase) {
                                    $trainingCount++;
                                    if ($trainingCount <= 3) {
                                        $parts = $phrase->getParts();
                                        if ($parts) {
                                            $text = '';
                                            foreach ($parts as $part) {
                                                $text .= $part->getText();
                                            }
                                            if (trim($text)) {
                                                $trainingTexts[] = '"' . $text . '"';
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                \Log::warning("Error processing training phrases: " . $e->getMessage());
                            }
                        }
                        
                        // Count responses
                        $messages = $intent->getMessages();
                        $responseCount = 0;
                        $responseTexts = [];
                        
                        if ($messages) {
                            try {
                                foreach ($messages as $message) {
                                    $responseCount++;
                                    if ($responseCount <= 2) {
                                        $text = $message->getText();
                                        if ($text) {
                                            $textParts = $text->getText();
                                            if (count($textParts) > 0) {
                                                $responseText = implode(' ', $textParts);
                                                $responseTexts[] = '"' . substr($responseText, 0, 50) . '..."';
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                \Log::warning("Error processing messages: " . $e->getMessage());
                            }
                        }
                        
                        $intents[] = [
                            'id' => $counter,
                            'intent_name' => $intent->getName(),
                            'display_name' => $intent->getDisplayName(),
                            'training_phrases_text' => !empty($trainingTexts) ? implode(', ', $trainingTexts) : 'No training phrases',
                            'training_phrases_count' => $trainingCount,
                            'responses_text' => !empty($responseTexts) ? implode(', ', $responseTexts) : 'No responses',
                            'responses_count' => $responseCount,
                            'status' => 'Regular',
                            'last_modified' => date('Y-m-d H:i:s'),
                            'actions' => 'Edit | Delete'
                        ];
                    }
                }
                
                \Log::info("DialogflowSync: Total intents found: " . $counter);
                
                if ($counter > 0) {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'intents_synced' => $counter,
                            'intents' => $intents,
                            'is_mock_data' => false,
                            'debug' => 'Successfully fetched ' . $counter . ' intents'
                        ]
                    ])->withHeaders($headers);
                } else {
                    \Log::warning('DialogflowSync: No intents found, checking agent configuration');
                    
                    // TEST 2: Try to get agent info to verify connection
                    try {
                        $agentClient = new \Google\Cloud\Dialogflow\V2\AgentsClient([
                            'credentials' => $credentials,
                            'projectId' => $projectId
                        ]);
                        
                        $agent = $agentClient->getAgent("projects/{$projectId}");
                        \Log::info('DialogflowSync: Agent info', [
                            'agent_name' => $agent->getDisplayName(),
                            'default_language' => $agent->getDefaultLanguageCode(),
                            'time_zone' => $agent->getTimeZone()
                        ]);
                        
                        return response()->json([
                            'success' => true,
                            'data' => [
                                'intents_synced' => 0,
                                'intents' => [],
                                'is_mock_data' => false,
                                'debug' => [
                                    'message' => 'Agent exists but no intents found',
                                    'agent_name' => $agent->getDisplayName(),
                                    'default_language' => $agent->getDefaultLanguageCode(),
                                    'note' => 'Check if intents exist in Dialogflow Console'
                                ]
                            ]
                        ])->withHeaders($headers);
                        
                    } catch (\Exception $agentError) {
                        \Log::error('DialogflowSync: Agent check failed: ' . $agentError->getMessage());
                    }
                }
                
            } catch (\Throwable $e) {
                \Log::error('DialogflowSync: API Error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Provide helpful error message
                $errorMessage = $e->getMessage();
                $helpMessage = '';
                
                if (strpos($errorMessage, 'PERMISSION_DENIED') !== false) {
                    $helpMessage = 'Check if the service account has Dialogflow API access';
                } elseif (strpos($errorMessage, 'NOT_FOUND') !== false) {
                    $helpMessage = 'Agent not found. Check project ID: ' . $projectId;
                } elseif (strpos($errorMessage, 'INVALID_ARGUMENT') !== false) {
                    $helpMessage = 'Invalid request. Check parent path format';
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Dialogflow API Error: ' . $errorMessage,
                    'help' => $helpMessage,
                    'data' => [
                        'intents_synced' => 0,
                        'intents' => $this->getMockIntents(),
                        'is_mock_data' => true,
                        'debug' => [
                            'project_id' => $projectId,
                            'error' => $errorMessage
                        ]
                    ]
                ], 500)->withHeaders($headers);
            }
            
            // If we get here with 0 intents but no error, return empty
            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => 0,
                    'intents' => [],
                    'is_mock_data' => false,
                    'debug' => 'No intents found in the Dialogflow agent. Check: 1. Agent has intents 2. Service account has permissions 3. Correct project ID'
                ]
            ])->withHeaders($headers);
            
        } catch (\Throwable $e) {
            \Log::error('DialogflowSync: General Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
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
                'training_phrases_text' => '"How to apply for study grant?", "What is study grant?"',
                'training_phrases_count' => 5,
                'responses_text' => '"Study grants are available for all employees..."',
                'responses_count' => 2,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ],
            [
                'id' => 2,
                'intent_name' => 'projects/aihra-472311/agent/intents/002f487e-ab1f-4757-99c4-d74b1ad0aa94',
                'display_name' => 'EmployeeDevelopment_Seminars_003',
                'training_phrases_text' => '"What seminars available?", "Register for seminar"',
                'training_phrases_count' => 3,
                'responses_text' => '"Seminars are scheduled monthly..."',
                'responses_count' => 1,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ]
        ];
    }
    
    private function getDialogflowCredentials()
    {
        \Log::info('DialogflowSync: Checking credentials');
        
        // Method 1: Full JSON from env
        $fullJson = env('DIALOGFLOW_CREDENTIALS_JSON');
        if ($fullJson) {
            \Log::info('DialogflowSync: Found DIALOGFLOW_CREDENTIALS_JSON');
            $credentials = json_decode($fullJson, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($credentials['type']) && $credentials['type'] === 'service_account') {
                \Log::info('DialogflowSync: Valid service account JSON found');
                return $credentials;
            } else {
                \Log::error('DialogflowSync: Invalid JSON or not service account');
            }
        }
        
        // Method 2: Individual pieces
        $privateKey = env('DIALOGFLOW_PRIVATE_KEY');
        $clientEmail = env('DIALOGFLOW_CLIENT_EMAIL');
        
        if ($privateKey && $clientEmail) {
            \Log::info('DialogflowSync: Building credentials from env variables');
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
        
        // Method 3: Credentials file
        $filePath = storage_path('app/dialogflow.json');
        if (file_exists($filePath)) {
            \Log::info('DialogflowSync: Found credentials file at ' . $filePath);
            $fileContent = file_get_contents($filePath);
            $credentials = json_decode($fileContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $credentials;
            }
        }
        
        // Method 4: Google Application Default Credentials
        $gcloudPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if ($gcloudPath && file_exists($gcloudPath)) {
            \Log::info('DialogflowSync: Using GOOGLE_APPLICATION_CREDENTIALS from ' . $gcloudPath);
            $fileContent = file_get_contents($gcloudPath);
            $credentials = json_decode($fileContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $credentials;
            }
        }
        
        \Log::warning('DialogflowSync: No credentials found');
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
