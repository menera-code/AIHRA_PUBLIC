<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Google\Cloud\Dialogflow\V2\GetIntentRequest;
use Google\Cloud\Dialogflow\V2\Intent\TrainingPhrase;
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
            
            // Method 1: Try with default language (no language code)
            try {
                $requestObj = new ListIntentsRequest();
                $requestObj->setParent($parent);
                // Don't set language code - use default
                $requestObj->setPageSize(50); // Get first 50
                
                $response = $client->listIntents($requestObj);
                $intents = $this->processIntents($response, $client, $projectId);
                
            } catch (\Throwable $e) {
                \Log::error("Failed with default language: " . $e->getMessage());
                
                // Method 2: Try with empty string language
                try {
                    $requestObj = new ListIntentsRequest();
                    $requestObj->setParent($parent);
                    $requestObj->setLanguageCode('');
                    $requestObj->setPageSize(50);
                    
                    $response = $client->listIntents($requestObj);
                    $intents = $this->processIntents($response, $client, $projectId);
                    
                } catch (\Throwable $e2) {
                    throw new \Exception("Failed to fetch intents: " . $e2->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => count($intents),
                    'intents' => $intents,
                    'is_mock_data' => false
                ]
            ])->withHeaders($headers);

        } catch (\Throwable $e) {
            \Log::error('DialogflowSync Error: ' . $e->getMessage());
            
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
    
    private function processIntents($response, $client, $projectId)
    {
        $intents = [];
        $counter = 1;
        
        foreach ($response->iterateAllElements() as $intent) {
            // Debug the first intent to see structure
            if ($counter === 1) {
                $this->debugIntentStructure($intent);
            }
            
            // Get training phrases - SIMPLIFIED APPROACH
            $trainingPhrases = $intent->getTrainingPhrases();
            $trainingCount = 0;
            $trainingTexts = [];
            
            if ($trainingPhrases) {
                // Try multiple counting methods
                try {
                    // Method 1: iterator_count
                    $trainingCount = iterator_count($trainingPhrases);
                } catch (\Exception $e) {
                    // Method 2: Manual iteration
                    $tempCount = 0;
                    foreach ($trainingPhrases as $phrase) {
                        $tempCount++;
                        if ($tempCount <= 3 && $phrase instanceof TrainingPhrase) {
                            $text = $this->extractTextFromTrainingPhrase($phrase);
                            if ($text) {
                                $trainingTexts[] = '"' . $text . '"';
                            }
                        }
                    }
                    $trainingCount = $tempCount;
                }
            }
            
            // Get responses
            $messages = $intent->getMessages();
            $responseCount = 0;
            $responseTexts = [];
            
            if ($messages) {
                try {
                    $responseCount = iterator_count($messages);
                    
                    $msgCounter = 0;
                    foreach ($messages as $message) {
                        $msgCounter++;
                        if ($msgCounter > 2) break;
                        
                        $text = $message->getText();
                        if ($text) {
                            $textParts = $text->getText();
                            if ($textParts) {
                                $fullText = '';
                                foreach ($textParts as $part) {
                                    $fullText .= $part . ' ';
                                }
                                if (trim($fullText)) {
                                    $responseTexts[] = '"' . $this->cleanText(substr(trim($fullText), 0, 50)) . '..."';
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Log::warning("Error counting messages: " . $e->getMessage());
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
            
            $intents[] = [
                'id' => $counter++,
                'intent_name' => $intent->getName(),
                'display_name' => $intent->getDisplayName(),
                'training_phrases_text' => !empty($trainingTexts) ? implode(', ', $trainingTexts) : 'No training phrases',
                'training_phrases_count' => $trainingCount,
                'responses_text' => !empty($responseTexts) ? implode(', ', $responseTexts) : 'No responses',
                'responses_count' => $responseCount,
                'status' => $status,
                'last_modified' => $lastModified,
                'actions' => 'Edit | Delete'
            ];
            
            // Stop after 50 intents for testing
            if ($counter > 50) {
                break;
            }
        }
        
        return $intents;
    }
    
    private function debugIntentStructure($intent)
    {
        \Log::info('=== INTENT STRUCTURE DEBUG ===');
        \Log::info('Display Name: ' . $intent->getDisplayName());
        
        $trainingPhrases = $intent->getTrainingPhrases();
        \Log::info('Training Phrases Object:', [
            'type' => gettype($trainingPhrases),
            'class' => $trainingPhrases ? get_class($trainingPhrases) : 'null',
            'is_iterable' => is_iterable($trainingPhrases),
            'is_countable' => is_countable($trainingPhrases),
        ]);
        
        if ($trainingPhrases && is_iterable($trainingPhrases)) {
            $count = 0;
            foreach ($trainingPhrases as $phrase) {
                $count++;
                if ($count <= 2) {
                    \Log::info("Training Phrase #{$count}:", [
                        'class' => get_class($phrase),
                        'methods' => get_class_methods($phrase)
                    ]);
                    
                    if ($phrase instanceof TrainingPhrase) {
                        $parts = $phrase->getParts();
                        \Log::info("  Parts:", [
                            'type' => gettype($parts),
                            'class' => get_class($parts),
                            'is_iterable' => is_iterable($parts)
                        ]);
                        
                        if ($parts && is_iterable($parts)) {
                            $partCount = 0;
                            foreach ($parts as $part) {
                                $partCount++;
                                \Log::info("  Part #{$partCount}: " . $part->getText());
                                if ($partCount >= 2) break;
                            }
                        }
                    }
                }
                if ($count >= 5) break;
            }
            \Log::info("Total training phrases found by iteration: " . $count);
        }
        
        // Try alternative method to get training phrases
        if (method_exists($intent, 'serializeToJsonString')) {
            try {
                $json = $intent->serializeToJsonString();
                $data = json_decode($json, true);
                if (isset($data['trainingPhrases'])) {
                    \Log::info('Training phrases in JSON:', [
                        'count' => count($data['trainingPhrases']),
                        'sample' => array_slice($data['trainingPhrases'], 0, 2)
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning('Could not serialize to JSON: ' . $e->getMessage());
            }
        }
    }
    
    private function extractTextFromTrainingPhrase($phrase)
    {
        if (!$phrase instanceof TrainingPhrase) {
            return '';
        }
        
        $text = '';
        $parts = $phrase->getParts();
        
        if ($parts && is_iterable($parts)) {
            foreach ($parts as $part) {
                $text .= $part->getText();
            }
        }
        
        return $this->cleanText($text);
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
                'training_phrases_text' => '"How to apply for study grant?", "What is eligibility?"',
                'training_phrases_count' => 5,
                'responses_text' => '"Study grants help employees..."',
                'responses_count' => 2,
                'status' => 'Regular',
                'last_modified' => date('Y-m-d H:i:s'),
                'actions' => 'Edit | Delete'
            ],
            [
                'id' => 2,
                'intent_name' => 'projects/aihra-472311/agent/intents/mock-2',
                'display_name' => 'EmployeeDevelopment_Seminars_003',
                'training_phrases_text' => '"Available seminars?", "How to register?"',
                'training_phrases_count' => 3,
                'responses_text' => '"Seminars improve skills..."',
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
