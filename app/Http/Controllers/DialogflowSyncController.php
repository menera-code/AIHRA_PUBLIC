<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\IntentsClient;
use Illuminate\Http\JsonResponse;

class DialogflowSyncController extends Controller
{
    public function sync(): JsonResponse
    {
        try {
            // Get credentials from environment variable or file
            $credentials = $this->getDialogflowCredentials();
            
            $client = new IntentsClient([
                'credentials' => $credentials,
                'projectId' => env('DIALOGFLOW_PROJECT_ID')
            ]);

            $parent = $client->agentName(env('DIALOGFLOW_PROJECT_ID'));

            $intents = [];
            foreach ($client->listIntents($parent) as $intent) {
                $intents[] = [
                    'id' => $intent->getName(),
                    'display_name' => $intent->getDisplayName(),
                    'training_phrases' => count($intent->getTrainingPhrases()),
                ];
            }

            $client->close();

            return response()->json([
                'success' => true,
                'intents' => $intents
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    private function getDialogflowCredentials()
    {
        // Check if JSON string is in environment variable (GitHub Actions)
        if ($json = env('DIALOGFLOW_CREDENTIALS_JSON')) {
            // Return as array (parsed JSON)
            return json_decode($json, true);
        }
        
        // Fallback to file path for local development
        $filePath = storage_path('app/dialogflow/dialogflow.json');
        
        if (!file_exists($filePath)) {
            throw new \Exception('Dialogflow credentials not found. Set DIALOGFLOW_CREDENTIALS_JSON env variable or create credentials file.');
        }
        
        return $filePath;
    }
}
