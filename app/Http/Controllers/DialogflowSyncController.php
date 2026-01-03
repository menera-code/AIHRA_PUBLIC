<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Google\Cloud\Dialogflow\V2\Intent\TrainingPhrase;
use Google\Cloud\Dialogflow\V2\Intent\Message;
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
                        'intents_synced' => 6,
                        'intents' => [
                            [
                                'id' => 1,
                                'intent_name' => 'projects/aihra-472311/agent/intents/0008b207-67fa-42cf-abd1-db1fbdbc2fc8',
                                'display_name' => 'EmployeeDevelopment_StudyGrantC_016',
                                'training_phrases_text' => '"How do I apply for study grant?", "What are the eligibility criteria?"',
                                'training_phrases_count' => 2,
                                'responses_text' => '"Study grants are available for...", "You need to submit..."',
                                'responses_count' => 2,
                                'status' => 'Regular',
                                'last_modified' => '2024-01-08 14:30:00',
                                'actions' => 'Edit | Delete'
                            ],
                            [
                                'id' => 2,
                                'intent_name' => 'projects/aihra-472311/agent/intents/002f487e-ab1f-4757-99c4-d74b1ad0aa94',
                                'display_name' => 'EmployeeDevelopment_Seminars_003',
                                'training_phrases_text' => '"What seminars are available?", "How to register for seminars?"',
                                'training_phrases_count' => 2,
                                'responses_text' => '"Upcoming seminars include...", "Register through the portal..."',
                                'responses_count' => 2,
                                'status' => 'Regular',
                                'last_modified' => '2024-01-08 14:25:00',
                                'actions' => 'Edit | Delete'
                            ]
                        ],
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
            $requestObj->setLanguageCode('en-US');
            $requestObj->setPageSize(100);
            
            $response = $client->listIntents($requestObj);
            $intents = [];
            $counter = 1;
            
            foreach ($response->iterateAllElements() as $intent) {
                // Get training phrases
                $trainingPhrasesCount = 0;
                $trainingPhrasesText = '';
                $trainingPhrasesList = $intent->getTrainingPhrases();
                
                if ($trainingPhrasesList) {
                    $trainingPhrasesArray = iterator_to_array($trainingPhrasesList);
                    $trainingPhrasesCount = count($trainingPhrasesArray);
                    
                    // Build training phrases text with quotes
                    $phraseTexts = [];
                    foreach ($trainingPhrasesArray as $phrase) {
                        if ($phrase instanceof TrainingPhrase) {
                            $parts = $phrase->getParts();
                            $text = '';
                            foreach ($parts as $part) {
                                $text .= $part->getText();
                            }
                            if (!empty(trim($text))) {
                                $phraseTexts[] = '"' . $text . '"';
                            }
                        }
                    }
                    $trainingPhrasesText = implode(', ', array_slice($phraseTexts, 0, 3)); // Show first 3
                    if (count($phraseTexts) > 3) {
                        $trainingPhrasesText .= '...';
                    }
                }
                
                // Get responses
                $responsesCount = 0;
                $responsesText = '';
                $messagesList = $intent->getMessages();
                
                if ($messagesList) {
                    $messagesArray = iterator_to_array($messagesList);
                    $responsesCount = count($messagesArray);
                    
                    // Build responses text
                    $responseTexts = [];
                    foreach ($messagesArray as $message) {
                        if ($message instanceof Message) {
                            $text = $message->getText();
                            if ($text) {
                                $textParts = $text->getText();
                                if (count($textParts) > 0) {
                                    $responseText = implode(' ', $textParts);
                                    if (!empty(trim($responseText))) {
                                        $responseTexts[] = '"' . substr($responseText, 0, 50) . '..."'; // Truncate
                                    }
                                }
                            }
                        }
                    }
                    $responsesText = implode(', ', array_slice($responseTexts, 0, 2)); // Show first 2
                    if (count($responseTexts) > 2) {
                        $responsesText .= '...';
                    }
                }
                
                // Determine status
                $status = 'Regular';
                if (method_exists($intent, 'getIsFallback') && $intent->getIsFallback()) {
                    $status = 'Fallback';
                }
                
                // Get last modified (if available)
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
                    'training_phrases_text' => $trainingPhrasesText ?: '0',
                    'training_phrases_count' => $trainingPhrasesCount,
                    'responses_text' => $responsesText ?: '0',
                    'responses_count' => $responsesCount,
                    'status' => $status,
                    'last_modified' => $lastModified,
                    'actions' => 'Edit | Delete'
                ];
            }

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
            \Log::error('DialogflowSync Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Dialogflow sync failed: ' . $e->getMessage(),
                'data' => [
                    'intents_synced' => 0,
                    'intents' => [],
                    'is_mock_data' => true
                ]
            ], 500)->withHeaders($headers);
        }
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
