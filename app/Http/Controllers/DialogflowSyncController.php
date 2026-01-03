<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Google\Cloud\Dialogflow\V2\Intent\TrainingPhrase;
use Google\Cloud\Dialogflow\V2\Intent\Message;
use Google\Cloud\Dialogflow\V2\Intent\Message\Text;
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
            
            // Try different language codes
            $languages = ['', 'en', 'en-US', 'en-GB', 'en-IN'];
            $allIntents = [];
            $counter = 1;
            
            foreach ($languages as $language) {
                try {
                    \Log::info("Trying to fetch intents with language: " . ($language ?: 'default'));
                    
                    $requestObj = new ListIntentsRequest();
                    $requestObj->setParent($parent);
                    if (!empty($language)) {
                        $requestObj->setLanguageCode($language);
                    }
                    $requestObj->setPageSize(20); // Get first 20 for testing
                    
                    $response = $client->listIntents($requestObj);
                    $intentsFromThisLanguage = [];
                    
                    foreach ($response->iterateAllElements() as $intent) {
                        $intentId = $intent->getName();
                        
                        // Skip if we already have this intent
                        if (isset($allIntents[$intentId])) {
                            continue;
                        }
                        
                        // DEBUG: Log raw training phrases data
                        $trainingPhrases = $intent->getTrainingPhrases();
                        \Log::info("Intent: " . $intent->getDisplayName(), [
                            'language' => $language ?: 'default',
                            'has_training_phrases' => !empty($trainingPhrases),
                            'training_phrases_type' => gettype($trainingPhrases),
                            'training_phrases_class' => $trainingPhrases ? get_class($trainingPhrases) : 'null',
                            'is_iterable' => is_iterable($trainingPhrases),
                            'methods' => $trainingPhrases ? get_class_methods($trainingPhrases) : []
                        ]);
                        
                        // Process training phrases with detailed debugging
                        $trainingCount = 0;
                        $trainingTexts = [];
                        
                        if ($trainingPhrases && is_iterable($trainingPhrases)) {
                            // Method 1: Try iterator_count
                            try {
                                $trainingCount = iterator_count($trainingPhrases);
                                \Log::info("Method 1 - iterator_count: " . $trainingCount);
                            } catch (\Exception $e) {
                                \Log::warning("iterator_count failed: " . $e->getMessage());
                            }
                            
                            // Method 2: Try to iterate and count
                            if ($trainingCount === 0) {
                                $tempCount = 0;
                                foreach ($trainingPhrases as $phrase) {
                                    $tempCount++;
                                    
                                    // Get text from training phrase
                                    if ($phrase instanceof TrainingPhrase) {
                                        $parts = $phrase->getParts();
                                        $text = '';
                                        
                                        if ($parts && is_iterable($parts)) {
                                            foreach ($parts as $part) {
                                                $text .= $part->getText();
                                            }
                                        }
                                        
                                        if (trim($text) && $tempCount <= 3) {
                                            $trainingTexts[] = '"' . $this->cleanText($text) . '"';
                                        }
                                    }
                                }
                                $trainingCount = $tempCount;
                                \Log::info("Method 2 - manual iteration count: " . $trainingCount);
                            }
                            
                            // Method 3: Try to convert to array
                            if ($trainingCount === 0) {
                                try {
                                    $array = iterator_to_array($trainingPhrases);
                                    $trainingCount = count($array);
                                    \Log::info("Method 3 - iterator_to_array count: " . $trainingCount);
                                } catch (\Exception $e) {
                                    \Log::warning("iterator_to_array failed: " . $e->getMessage());
                                }
                            }
                        }
                        
                        // Process responses
                        $responseCount = 0;
                        $responseTexts = [];
                        $messages = $intent->getMessages();
                        
                        if ($messages && is_iterable($messages)) {
                            $responseCount = iterator_count($messages);
                            
                            $msgCounter = 0;
                            foreach ($messages as $message) {
                                $msgCounter++;
                                if ($msgCounter > 2) break;
                                
                                if ($message instanceof Message) {
                                    $text = $message->getText();
                                    if ($text instanceof Text) {
                                        $textParts = $text->getText();
                                        $fullText = '';
                                        
                                        if ($textParts && is_iterable($textParts)) {
                                            foreach ($textParts as $part) {
                                                $fullText .= $part . ' ';
                                            }
                                        }
                                        
                                        if (trim($fullText)) {
                                            $responseTexts[] = '"' . $this->cleanText(substr(trim($fullText), 0, 50)) . '..."';
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Determine status
                        $status = 'Regular';
                        if (method_exists($intent, 'getIsFallback') && $intent->getIsFallback()) {
                            $status = 'Fallback';
                        }
                        
                        // Get last modified
                        $lastModified = 'N/A';
                        if (method_exists($intent, 'getUpdateTime')) {
                            $updateTime = $intent->getUpdateTime();
                            if ($updateTime) {
                                $lastModified = $updateTime->toDateTime()->format('Y-m-d H:i:s');
                            }
                        }
                        
                        $allIntents[$intentId] = [
                            'id' => $counter++,
                            'intent_name' => $intentId,
                            'display_name' => $intent->getDisplayName(),
                            'training_phrases_text' => !empty($trainingTexts) ? implode(', ', $trainingTexts) : 'No training phrases',
                            'training_phrases_count' => $trainingCount,
                            'responses_text' => !empty($responseTexts) ? implode(', ', $responseTexts) : 'No responses',
                            'responses_count' => $responseCount,
                            'status' => $status,
                            'last_modified' => $lastModified,
                            'actions' => 'Edit | Delete',
                            'language_found' => $language ?: 'default'
                        ];
                        
                        // Log success for first few intents
                        if ($counter <= 3) {
                            \Log::info("Processed intent successfully", [
                                'name' => $intent->getDisplayName(),
                                'training_count' => $trainingCount,
                                'response_count' => $responseCount
                            ]);
                        }
                    }
                    
                    \Log::info("Found " . count($intentsFromThisLanguage) . " intents with language: " . ($language ?: 'default'));
                    
                } catch (\Throwable $e) {
                    \Log::warning("Failed with language '{$language}': " . $e->getMessage());
                    continue;
                }
            }
            
            // Convert associative array to indexed array
            $intents = array_values($allIntents);
            
            // If still no training phrases, try one more approach
            if (!empty($intents) && $intents[0]['training_phrases_count'] === 0) {
                \Log::warning("All training phrases are 0, trying direct intent fetch");
                
                // Try to fetch one intent directly to debug
                try {
                    $sampleIntentName = $intents[0]['intent_name'];
                    $specificIntent = $client->getIntent($sampleIntentName);
                    
                    \Log::info("Direct intent fetch for debugging", [
                        'intent_name' => $specificIntent->getDisplayName(),
                        'raw_training_phrases' => $this->debugTrainingPhrases($specificIntent->getTrainingPhrases())
                    ]);
                    
                } catch (\Exception $e) {
                    \Log::error("Failed to fetch specific intent: " . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => count($intents),
                    'intents' => $intents,
                    'is_mock_data' => false,
                    'debug_info' => [
                        'total_intents' => count($intents),
                        'sample_training_count' => !empty($intents) ? $intents[0]['training_phrases_count'] : 0,
                        'sample_response_count' => !empty($intents) ? $intents[0]['responses_count'] : 0
                    ]
                ]
            ])->withHeaders($headers);

        } catch (\Throwable $e) {
            \Log::error('DialogflowSync Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
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
    
    private function debugTrainingPhrases($trainingPhrases)
    {
        if (!$trainingPhrases) {
            return 'null';
        }
        
        $result = [
            'type' => gettype($trainingPhrases),
            'class' => get_class($trainingPhrases),
            'is_iterable' => is_iterable($trainingPhrases),
            'is_countable' => is_countable($trainingPhrases),
        ];
        
        if (is_iterable($trainingPhrases)) {
            $items = [];
            $count = 0;
            foreach ($trainingPhrases as $item) {
                $count++;
                if ($count <= 3) {
                    $items[] = [
                        'type' => get_class($item),
                        'methods' => get_class_methods($item)
                    ];
                }
            }
            $result['iterated_count'] = $count;
            $result['sample_items'] = $items;
        }
        
        return $result;
    }
    
    private function cleanText($text)
    {
        $text = trim($text);
        $text = str_replace('"', '\"', $text);
        if (strlen($text) > 100) {
            $text = substr($text, 0, 97) . '...';
        }
        return $text;
    }
    
    private function getMockIntents()
    {
        return [
            [
                'id' => 1,
                'intent_name' => 'projects/aihra-472311/agent/intents/mock-1',
                'display_name' => 'EmployeeDevelopment_StudyGrantC_016',
                'training_phrases_text' => '"How to apply?", "Eligibility criteria"',
                'training_phrases_count' => 5,
                'responses_text' => '"Study grants available..."',
                'responses_count' => 2,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ],
            [
                'id' => 2,
                'intent_name' => 'projects/aihra-472311/agent/intents/mock-2',
                'display_name' => 'EmployeeDevelopment_Seminars_003',
                'training_phrases_text' => '"Available seminars?", "Registration process"',
                'training_phrases_count' => 3,
                'responses_text' => '"Monthly seminars..."',
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
