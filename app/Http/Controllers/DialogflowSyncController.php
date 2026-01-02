<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\IntentsClient;
use Illuminate\Http\JsonResponse;

class DialogflowSyncController extends Controller
{
    public function sync(): JsonResponse
    {
        try {
            // Use the JSON file you already have
            $client = new IntentsClient([
                'credentials' => storage_path('app/dialogflow/dialogflow.json')
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
}
