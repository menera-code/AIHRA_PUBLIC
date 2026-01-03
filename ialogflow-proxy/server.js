const express = require('express');
const cors = require('cors');
const axios = require('axios');
const { GoogleAuth } = require('google-auth-library');
require('dotenv').config();

const app = express();
app.use(cors());
app.use(express.json());

// Dialogflow credentials from Render environment variables
const DIALOGFLOW_PROJECT_ID = process.env.DIALOGFLOW_PROJECT_ID;
const DIALOGFLOW_CREDENTIALS = process.env.DIALOGFLOW_CREDENTIALS ? 
  JSON.parse(process.env.DIALOGFLOW_CREDENTIALS) : null;

// Route to handle chat messages from x10hosting
app.post('/chat', async (req, res) => {
  try {
    const { message, sessionId = 'default-session' } = req.body;
    
    if (!message) {
      return res.status(400).json({ error: 'Message is required' });
    }
    
    // 1. Get authentication token for Dialogflow
    const authToken = await getDialogflowToken();
    
    // 2. Send message to Dialogflow
    const dialogflowResponse = await sendToDialogflow(
      message, 
      sessionId, 
      authToken
    );
    
    // 3. Return response to x10hosting
    res.json({
      success: true,
      ...dialogflowResponse,
      timestamp: new Date().toISOString()
    });
    
  } catch (error) {
    console.error('Error:', error);
    res.status(500).json({ 
      success: false,
      error: error.message,
      reply: "I'm having trouble connecting to the AI service right now. Please try again later."
    });
  }
});

// Get Dialogflow authentication token
async function getDialogflowToken() {
  if (!DIALOGFLOW_CREDENTIALS) {
    throw new Error('Dialogflow credentials not configured');
  }
  
  const auth = new GoogleAuth({
    credentials: DIALOGFLOW_CREDENTIALS,
    scopes: 'https://www.googleapis.com/auth/cloud-platform',
  });
  
  const client = await auth.getClient();
  const token = await client.getAccessToken();
  return token.token;
}

// Send message to Dialogflow
async function sendToDialogflow(message, sessionId, authToken) {
  const url = `https://dialogflow.googleapis.com/v2/projects/${DIALOGFLOW_PROJECT_ID}/agent/sessions/${sessionId}:detectIntent`;
  
  const response = await axios.post(url, {
    queryInput: {
      text: {
        text: message,
        languageCode: 'en'
      }
    }
  }, {
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    timeout: 30000 // 30 second timeout
  });
  
  // Extract the response text
  const queryResult = response.data.queryResult;
  return {
    fulfillmentText: queryResult.fulfillmentText || queryResult.queryText,
    intentDetectionConfidence: queryResult.intentDetectionConfidence || 0,
    intent: {
      displayName: queryResult.intent?.displayName || 'Default Fallback Intent'
    }
  };
}

// Health check endpoint
app.get('/', (req, res) => {
  res.json({ 
    status: 'running',
    service: 'Dialogflow Proxy',
    timestamp: new Date().toISOString()
  });
});

// Test endpoint
app.get('/test', async (req, res) => {
  try {
    const authToken = await getDialogflowToken();
    res.json({
      success: true,
      message: 'Dialogflow proxy is working',
      projectId: DIALOGFLOW_PROJECT_ID,
      hasCredentials: !!DIALOGFLOW_CREDENTIALS
    });
  } catch (error) {
    res.status(500).json({
      success: false,
      error: error.message
    });
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Dialogflow proxy server running on port ${PORT}`);
  console.log(`Project ID: ${DIALOGFLOW_PROJECT_ID}`);
});

// Add this route to your existing server.js on Render
app.post('/chat', async (req, res) => {
  try {
    const { message, sessionId = 'default-session' } = req.body;
    
    if (!message) {
      return res.status(400).json({ error: 'Message is required' });
    }
    
    // Use your existing Dialogflow authentication
    const authToken = await getDialogflowToken(); // Use your existing auth function
    
    // Send to Dialogflow API
    const response = await fetch(`https://dialogflow.googleapis.com/v2/projects/${DIALOGFLOW_PROJECT_ID}/agent/sessions/${sessionId}:detectIntent`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        queryInput: {
          text: {
            text: message,
            languageCode: 'en'
          }
        }
      })
    });
    
    const data = await response.json();
    
    // Format response similar to your DialogflowService
    res.json({
      fulfillmentText: data.queryResult?.fulfillmentText || data.queryResult?.queryText || "I didn't get a response.",
      intentDetectionConfidence: data.queryResult?.intentDetectionConfidence || 0,
      intent: {
        displayName: data.queryResult?.intent?.displayName || 'Default Fallback Intent'
      }
    });
    
  } catch (error) {
    console.error('Chat error:', error);
    res.status(500).json({ 
      error: error.message,
      fulfillmentText: "I'm having trouble connecting right now. Please try again.",
      intentDetectionConfidence: 0,
      intent: { displayName: 'Error' }
    });
  }
});

// Test endpoint
app.get('/chat-test', async (req, res) => {
  try {
    const authToken = await getDialogflowToken();
    res.json({
      success: true,
      message: 'Chat endpoint is ready',
      projectId: DIALOGFLOW_PROJECT_ID,
      hasAuth: !!authToken
    });
  } catch (error) {
    res.status(500).json({
      success: false,
      error: error.message
    });
  }
});
