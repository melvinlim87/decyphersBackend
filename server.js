import express from 'express';
import cors from 'cors';
import yahooFinance from 'yahoo-finance2';
import fetch from 'node-fetch';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import functions from 'firebase-functions';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

dotenv.config({ path: path.join(__dirname, '..', '.env') });

const app = express();
const port = process.env.PORT || 3005;

// Configure CORS with more detailed options
const allowedOrigins = [
  'https://aimarketanalyzer.netlify.app',
  'https://api-pv6ncl3mrq-uc.a.run.app',
  'http://localhost:5173',
  'http://localhost:3000'
];

const corsOptions = {
  origin: function (origin, callback) {
    if (!origin || allowedOrigins.includes(origin)) {
      callback(null, true);
    } else {
      callback(new Error('Not allowed by CORS'));
    }
  },
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Accept', 'Authorization'],
  credentials: true,
  maxAge: 86400 // Cache preflight request for 24 hours
};

app.use(cors(corsOptions));
app.options('*', cors(corsOptions));

app.use(express.json());

// Map frontend intervals to Yahoo Finance intervals
const intervalMap = {
  '1m': '1m',
  '5m': '5m',
  '15m': '15m',
  '30m': '30m',
  '1h': '60m',
  '1d': '1d',
  '1w': '1wk',
  '1M': '1mo'
};

// Chart proxy endpoint
app.get('/api/chart', cors(corsOptions), async (req, res) => {
  try {
    const { interval, height, symbol } = req.query;
    
    if (!process.env.VITE_CHART_IMG_API_KEY) {
      return res.status(500).json({
        error: 'Server configuration error',
        details: 'API key not configured'
      });
    }

    // Construct the chart-img.com URL
    const chartUrl = new URL('https://api.chart-img.com/v1/tradingview/advanced-chart');
    
    // Add parameters
    chartUrl.searchParams.append('interval', interval || '4h');
    chartUrl.searchParams.append('height', height || '300');
    chartUrl.searchParams.append('symbol', symbol || 'EURUSD');
    chartUrl.searchParams.append('key', process.env.VITE_CHART_IMG_API_KEY);

    const response = await fetch(chartUrl, {
      headers: {
        'Accept': 'image/png,image/*',
        'User-Agent': 'AI Market Analyzer'
      }
    });

    if (!response.ok) {
      return res.status(response.status).json({
        error: 'Chart API error',
        details: await response.text()
      });
    }

    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('image')) {
      return res.status(400).json({
        error: 'Invalid response',
        details: 'Expected image response'
      });
    }

    // Set CORS headers dynamically based on origin
    const requestOrigin = req.get('origin');
    if (requestOrigin && allowedOrigins.includes(requestOrigin)) {
      res.set({
        'Access-Control-Allow-Origin': requestOrigin,
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Accept, Authorization',
        'Access-Control-Max-Age': '86400',
        'Content-Type': contentType
      });
    }

    response.body.pipe(res);
  } catch (error) {
    res.status(500).json({
      error: 'Server error',
      details: error.message
    });
  }
});

app.get('/api/technical-indicators', async (req, res) => {
  try {
    let { symbol, interval } = req.query;
    
    // Format forex pairs
    if (symbol.length === 6 && !symbol.includes('.') && !symbol.includes('-')) {
      symbol = symbol + '=X';
    }

    console.log(`Fetching data for symbol: ${symbol}, interval: ${interval}`);

    // Map the interval to Yahoo Finance format
    const yahooInterval = intervalMap[interval] || '1d';
    console.log(`Mapped interval ${interval} to ${yahooInterval}`);

    try {
      // Calculate date range based on interval
      const now = new Date();
      let startDate;
      
      switch(yahooInterval) {
        case '1m':
        case '5m':
        case '15m':
        case '30m':
          startDate = new Date(now - 7 * 24 * 60 * 60 * 1000); // 7 days for minute-based intervals
          break;
        case '60m':
          startDate = new Date(now - 30 * 24 * 60 * 60 * 1000); // 30 days for hourly
          break;
        default:
          startDate = new Date(now - 365 * 24 * 60 * 60 * 1000); // 1 year for daily and above
      }

      console.log(`Fetching from ${startDate} to ${now}`);

      // Fetch historical data
      const history = await yahooFinance.historical(symbol, {
        period1: startDate,
        period2: now,
        interval: yahooInterval
      });

      if (!history || history.length === 0) {
        throw new Error('No historical data available for this symbol');
      }

      console.log(`Received ${history.length} data points`);

      // Calculate indicators
      const indicators = calculateIndicators(history);
      console.log(`Successfully calculated indicators for ${symbol}`);
      res.json(indicators);
    } catch (error) {
      console.error(`Error processing ${symbol}:`, error.message);
      res.status(500).json({ 
        error: `Failed to fetch data for ${symbol}: ${error.message}`,
        symbol,
        interval: yahooInterval
      });
    }
  } catch (error) {
    console.error('Server error:', error.message);
    res.status(500).json({ 
      error: error.message,
      symbol: req.query.symbol,
      interval: req.query.interval
    });
  }
});

function calculateIndicators(history) {
  // Calculate RSI
  const rsi = calculateRSI(history);
  
  // Calculate MACD
  const macd = calculateMACD(history);
  
  // Calculate Moving Averages
  const ma = calculateMovingAverages(history);

  // Generate signals
  const oscillatorSignals = analyzeOscillators(rsi, macd);
  const maSignals = analyzeMovingAverages(ma);
  const summarySignals = combinedAnalysis(oscillatorSignals, maSignals);

  return {
    oscillators: oscillatorSignals,
    summary: summarySignals,
    movingAverages: maSignals
  };
}

function calculateRSI(history, periods = 14) {
  const closes = history.map(bar => bar.close);
  const gains = [];
  const losses = [];
  
  for (let i = 1; i < closes.length; i++) {
    const change = closes[i] - closes[i - 1];
    gains.push(change > 0 ? change : 0);
    losses.push(change < 0 ? -change : 0);
  }

  const rsi = [];
  let avgGain = gains.slice(0, periods).reduce((a, b) => a + b, 0) / periods;
  let avgLoss = losses.slice(0, periods).reduce((a, b) => a + b, 0) / periods;

  for (let i = periods; i < closes.length; i++) {
    avgGain = ((avgGain * (periods - 1)) + gains[i - 1]) / periods;
    avgLoss = ((avgLoss * (periods - 1)) + losses[i - 1]) / periods;
    
    const rs = avgGain / avgLoss;
    rsi.push(100 - (100 / (1 + rs)));
  }

  return rsi;
}

function calculateMACD(history) {
  const closes = history.map(bar => bar.close);
  const ema12 = calculateEMA(closes, 12);
  const ema26 = calculateEMA(closes, 26);
  
  const macdLine = ema12.map((value, index) => value - ema26[index]);
  const signalLine = calculateEMA(macdLine, 9);
  const histogram = macdLine.map((value, index) => value - signalLine[index]);

  return {
    macd: macdLine,
    signal: signalLine,
    histogram
  };
}

function calculateEMA(data, periods) {
  const k = 2 / (periods + 1);
  const ema = [data[0]];
  
  for (let i = 1; i < data.length; i++) {
    ema.push(data[i] * k + ema[i - 1] * (1 - k));
  }
  
  return ema;
}

function calculateMovingAverages(history) {
  const closes = history.map(bar => bar.close);
  
  return {
    sma20: calculateSMA(closes, 20),
    sma50: calculateSMA(closes, 50),
    sma200: calculateSMA(closes, 200)
  };
}

function calculateSMA(data, periods) {
  const sma = [];
  for (let i = periods - 1; i < data.length; i++) {
    const sum = data.slice(i - periods + 1, i + 1).reduce((a, b) => a + b, 0);
    sma.push(sum / periods);
  }
  return sma;
}

function analyzeOscillators(rsi, macd) {
  let buy = 0;
  let sell = 0;
  let neutral = 0;

  // Analyze RSI
  const lastRSI = rsi[rsi.length - 1];
  if (lastRSI > 70) sell++;
  else if (lastRSI < 30) buy++;
  else neutral++;

  // Analyze MACD
  const lastMACD = macd.macd[macd.macd.length - 1];
  const lastSignal = macd.signal[macd.signal.length - 1];
  const lastHistogram = macd.histogram[macd.histogram.length - 1];
  
  if (lastMACD > lastSignal && lastHistogram > 0) buy++;
  else if (lastMACD < lastSignal && lastHistogram < 0) sell++;
  else neutral++;

  let signal = 'neutral';
  if (buy > sell && buy > neutral) signal = 'buy';
  else if (sell > buy && sell > neutral) signal = 'sell';

  return { sell, neutral, buy, signal };
}

function analyzeMovingAverages(ma) {
  let buy = 0;
  let sell = 0;
  let neutral = 0;

  const sma20 = ma.sma20[ma.sma20.length - 1];
  const sma50 = ma.sma50[ma.sma50.length - 1];
  const sma200 = ma.sma200[ma.sma200.length - 1];

  if (sma20 > sma50) buy++;
  else if (sma20 < sma50) sell++;
  else neutral++;

  if (sma50 > sma200) buy++;
  else if (sma50 < sma200) sell++;
  else neutral++;

  if (sma20 > sma200) buy++;
  else if (sma20 < sma200) sell++;
  else neutral++;

  let signal = 'neutral';
  if (buy > sell && buy > neutral) signal = 'buy';
  else if (sell > buy && sell > neutral) signal = 'sell';

  return { sell, neutral, buy, signal };
}

function combinedAnalysis(oscillators, movingAverages) {
  const sell = oscillators.sell + movingAverages.sell;
  const neutral = oscillators.neutral + movingAverages.neutral;
  const buy = oscillators.buy + movingAverages.buy;

  let signal = 'neutral';
  if (buy > sell && buy > neutral) signal = 'buy';
  else if (sell > buy && sell > neutral) signal = 'sell';

  return { sell, neutral, buy, signal };
}

// Add a health check endpoint
app.get('/health', (req, res) => {
  res.json({ status: 'ok' });
});

app.get('/', (req, res) => {
  res.json({ status: 'ok', message: 'AI Market Analyzer API is running' });
});

export const api = functions.https.onRequest(app);
