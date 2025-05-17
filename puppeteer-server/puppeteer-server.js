const fs = require('fs');
const express = require('express');
const puppeteer = require('puppeteer-core');
const {
    networkInterfaces
} = require('os');
const app = express();

// Configuration with improved settings and security limits
const CONFIG = {
    PORT: process.env.PORT || 3000, // Server port
    HOST: process.env.PUBLIC_SERVER === 'true' ? '0.0.0.0' : '127.0.0.1', // Binding address
    TIMEOUT: 30000,
    NAVIGATION_TIMEOUT: 25000,
    WAIT_AFTER_LOAD: 1000,
    DEFAULT_VIEWPORT: {
        width: 1280,
        height: 720,
        quality: 80,
        fullPage: false
    },
    USER_AGENT: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CHROME_PATHS: { // Possible Chrome/Chromium executable paths
        TERMUX: '/data/data/com.termux/files/usr/bin/chromium-browser',
        ANDROID_CHROME: '/data/data/com.android.chrome/app/chrome',
        CHROMIUM_LINUX: '/usr/bin/chromium',
        CHROME_LINUX: '/usr/bin/google-chrome-stable'
    },
    MAX_CONCURRENT_REQUESTS: 5,
    RATE_LIMIT_WINDOW_MS: 60000, // 1 minute
    RATE_LIMIT_MAX_REQUESTS: 20,
    MAX_URL_LENGTH: 2000,
    PAGE_LIFECYCLE_TIMEOUT: 10000, // Timeout for page creation/closing operations
    MEMORY_LIMIT_MB: 1024, // Memory threshold for browser restart
    MAX_PAGES_PER_BROWSER: 10,
    BROWSER_TTL_MS: 3600000, // 1 hour - browser instance time-to-live
};

// Enhanced logging function with colors
function log(message, type = 'info') {
    const timestamp = new Date().toISOString();
    const colors = {
        info: '\x1b[36m', // cyan
        success: '\x1b[32m', // green
        warning: '\x1b[33m', // yellow
        error: '\x1b[31m', // red
        reset: '\x1b[0m'
    };
    console.log(`${colors[type] || ''}[${timestamp}] ${message}${colors.reset}`);
}

// Get network interfaces information
function getNetworkInfo() {
    const nets = networkInterfaces();
    const results = [];

    for (const [name, addresses] of Object.entries(nets)) {
        for (const net of addresses) {
            if (!net.internal && net.family === 'IPv4') {
                results.push({
                    interface: name,
                    address: net.address
                });
            }
        }
    }
    return results;
}

// Check if file exists with error handling
function fileExists(path) {
    try {
        return fs.existsSync(path) && fs.statSync(path).isFile();
    } catch (error) {
        log(`File check failed for ${path}: ${error.message}`, 'warning');
        return false;
    }
}

// Tracking concurrent requests
let activeRequests = 0;
let pageCount = 0;
let browserStartTime = null;

// Simple rate limiter
const rateLimiter = {
    requests: {},

    checkLimit(ip) {
        const now = Date.now();
        if (!this.requests[ip]) {
            this.requests[ip] = {
                count: 1,
                resetTime: now + CONFIG.RATE_LIMIT_WINDOW_MS
            };
            return true;
        }

        // Reset counter if window expired
        if (now > this.requests[ip].resetTime) {
            this.requests[ip] = {
                count: 1,
                resetTime: now + CONFIG.RATE_LIMIT_WINDOW_MS
            };
            return true;
        }

        // Increment and check
        this.requests[ip].count++;
        return this.requests[ip].count <= CONFIG.RATE_LIMIT_MAX_REQUESTS;
    },

    // Clean expired entries periodically
    cleanupExpired() {
        const now = Date.now();
        Object.keys(this.requests).forEach(ip => {
            if (now > this.requests[ip].resetTime) {
                delete this.requests[ip];
            }
        });
    }
};

// Clean rate limiter data every minute
setInterval(() => rateLimiter.cleanupExpired(), 60000);

// Browser instance cache
let browserInstance = null;

// Check memory usage and restart browser if needed
async function checkMemoryAndRestart() {
    if (!browserInstance) return;

    const memoryUsage = process.memoryUsage();
    const usedMemoryMB = Math.round(memoryUsage.rss / 1024 / 1024);

    // Check if browser has been running too long or using too much memory
    const now = Date.now();
    const browserRunningMs = now - (browserStartTime || now);

    if (usedMemoryMB > CONFIG.MEMORY_LIMIT_MB || browserRunningMs > CONFIG.BROWSER_TTL_MS) {
        log(`Restarting browser: Memory usage: ${usedMemoryMB}MB, Running time: ${browserRunningMs/1000}s`, 'warning');
        try {
            const oldBrowser = browserInstance;
            browserInstance = null;
            await oldBrowser.close();
        } catch (error) {
            log(`Error closing browser during restart: ${error.message}`, 'error');
        }
    }
}

// Get or create browser instance
async function getBrowser() {
    // Check if we need to restart the browser due to memory/time constraints
    await checkMemoryAndRestart();

    // Reuse existing instance if available
    if (browserInstance) {
        try {
            await browserInstance.pages(); // Verify browser is still connected
            return browserInstance;
        } catch (error) {
            log(`Browser instance check failed: ${error.message}`, 'warning');
            browserInstance = null;
        }
    }

    // Try all possible browser paths
    for (const [name, path] of Object.entries(CONFIG.CHROME_PATHS)) {
        if (fileExists(path)) {
            try {
                log(`Attempting to launch browser: ${name} (${path})`);

                browserInstance = await puppeteer.launch({
                    executablePath: path,
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-extensions',
                        '--disable-audio-output',
                        '--disable-background-networking',
                        '--disable-component-extensions-with-background-pages',
                        `--js-flags=--max-old-space-size=${CONFIG.MEMORY_LIMIT_MB}`
                    ],
                    headless: true,
                    ignoreHTTPSErrors: true
                });

                // Handle browser disconnection
                browserInstance.on('disconnected', () => {
                    log('Browser disconnected', 'warning');
                    browserInstance = null;
                    browserStartTime = null;
                });

                browserStartTime = Date.now();
                pageCount = 0;
                log(`Browser launched successfully: ${name}`, 'success');
                return browserInstance;
            } catch (error) {
                log(`Failed to launch browser at ${path}: ${error.message}`, 'error');
            }
        }
    }
    throw new Error('No suitable browser found. Checked paths: ' + Object.values(CONFIG.CHROME_PATHS).join(', '));
}

// URL validation function
function isValidUrl(url) {
    if (!url) return false;
    if (url.length > CONFIG.MAX_URL_LENGTH) return false;

    try {
        let parsedUrl;
        // Add protocol if missing
        if (!url.includes('://')) {
            parsedUrl = new URL(`http://${url}`);
        } else {
            parsedUrl = new URL(url);
        }

        // Only allow http and https protocols
        return ['http:', 'https:'].includes(parsedUrl.protocol);
    } catch (e) {
        return false;
    }
}

// Configure JSON body parsing with validation
app.use(express.json({
    verify: (req, res, buf) => {
        try {
            JSON.parse(buf.toString()); // Validate JSON
        } catch (e) {
            res.status(400).json({
                error: 'Invalid JSON body'
            });
            throw new Error('Invalid JSON');
        }
    },
    limit: '1mb' // Request size limit reduced
}));

// Rate limiting middleware
app.use((req, res, next) => {
    const clientIp = req.ip || req.connection.remoteAddress;

    if (!rateLimiter.checkLimit(clientIp)) {
        log(`Rate limit exceeded for ${clientIp}`, 'warning');
        return res.status(429).json({
            error: 'Too many requests',
            retry_after: Math.ceil((rateLimiter.requests[clientIp].resetTime - Date.now()) / 1000)
        });
    }

    next();
});

// Screenshot endpoint
app.post('/screenshot', async(req, res) => {
    const startTime = Date.now();
    let browser = null;
    let page = null;

    // Check if too many concurrent requests
    if (activeRequests >= CONFIG.MAX_CONCURRENT_REQUESTS) {
        return res.status(503).json({
            error: 'Server busy',
            message: 'Too many concurrent requests. Please try again later.'
        });
    }

    activeRequests++;

    // Check for empty request body
    if (!req.body || Object.keys(req.body).length === 0) {
        activeRequests--;
        return res.status(400).json({
            error: 'Request body is empty or missing',
            example: {
                url: 'https://example.com',
                viewport: CONFIG.DEFAULT_VIEWPORT
            }
        });
    }

    // Cleanup function for resources
    const cleanup = async() => {
        activeRequests--;
        if (page) {
            try {
                const closePromise = page.close();
                const timeoutPromise = new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Page close timeout')), CONFIG.PAGE_LIFECYCLE_TIMEOUT)
                );
                await Promise.race([closePromise, timeoutPromise]);
                pageCount = Math.max(0, pageCount - 1);
            } catch (error) {
                log(`Failed to close page: ${error.message}`, 'warning');
            }
        }
    };

    // Request timeout handler
    const timeout = setTimeout(async() => {
        if (!res.headersSent) {
            res.status(504).json({
                error: 'Screenshot timed out',
                duration: (Date.now() - startTime) / 1000 + 's'
            });
        }
        await cleanup();
    }, CONFIG.TIMEOUT);

    try {
        // Destructure request parameters with validation
        const {
            url,
            viewport = {}
        } = req.body;

        // Validate required URL parameter
        if (!url) {
            clearTimeout(timeout);
            await cleanup();
            return res.status(400).json({
                error: 'URL is required in request body',
                received_body: req.body
            });
        }

        // Validate URL
        if (!isValidUrl(url)) {
            clearTimeout(timeout);
            await cleanup();
            return res.status(400).json({
                error: 'Invalid URL provided',
                message: 'URL must be a valid HTTP or HTTPS URL'
            });
        }

        log(`Processing screenshot request for: ${url}`);

        // Get browser instance
        browser = await getBrowser();

        // Check if we've reached the maximum page count
        if (pageCount >= CONFIG.MAX_PAGES_PER_BROWSER) {
            log('Maximum page count reached, restarting browser', 'warning');
            try {
                const oldBrowser = browserInstance;
                browserInstance = null;
                await oldBrowser.close();
                browser = await getBrowser();
            } catch (error) {
                log(`Error during browser restart: ${error.message}`, 'error');
            }
        }

        // Create new page with timeout
        const createPagePromise = browser.newPage();
        const pageTimeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Page creation timeout')), CONFIG.PAGE_LIFECYCLE_TIMEOUT)
        );

        page = await Promise.race([createPagePromise, pageTimeoutPromise]);
        pageCount++;

        // Configure page settings
        await page.setUserAgent(CONFIG.USER_AGENT);
        await page.setDefaultNavigationTimeout(CONFIG.NAVIGATION_TIMEOUT);
        await page.setViewport({
            width: Math.min(viewport.width || CONFIG.DEFAULT_VIEWPORT.width, 1920),
            height: Math.min(viewport.height || CONFIG.DEFAULT_VIEWPORT.height, 1080),
            deviceScaleFactor: 1
        });

        // Block unnecessary resource types to improve performance
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const resourceType = request.resourceType();
            if (['media', 'font', 'websocket'].includes(resourceType)) {
                request.abort();
            } else {
                request.continue();
            }
        });

        // Navigate to URL
        try {
            const normalizedUrl = url.includes('://') ? url : `http://${url}`;
            await page.goto(normalizedUrl, {
                waitUntil: 'networkidle2',
                timeout: CONFIG.NAVIGATION_TIMEOUT
            });
        } catch (navError) {
            // Continue despite navigation errors - we'll still try to take a screenshot
            log(`Navigation warning: ${navError.message}`, 'warning');
        }

        // Wait for additional time (compatibility with older Puppeteer versions)
        await new Promise(resolve => setTimeout(resolve, CONFIG.WAIT_AFTER_LOAD));

        // Take screenshot
        const screenshot = await page.screenshot({
            type: 'jpeg',
            quality: Math.min(Math.max(viewport.quality || CONFIG.DEFAULT_VIEWPORT.quality, 40), 90), // Ensure quality is between 40-90
            fullPage: !!viewport.fullPage, // Convert to boolean
            timeout: CONFIG.NAVIGATION_TIMEOUT
        });

        // Successful response
        clearTimeout(timeout);

        const duration = (Date.now() - startTime) / 1000;
        log(`Screenshot successful for ${url} (${duration}s)`, 'success');

        res.contentType('image/jpeg');
        res.set('X-Response-Time', `${duration}s`);
        res.send(screenshot);

        // Cleanup resources after sending response
        await cleanup();
    } catch (error) {
        // Error handling
        clearTimeout(timeout);

        log(`Screenshot failed: ${error.message}`, 'error');

        if (!res.headersSent) {
            res.status(500).json({
                error: 'Screenshot failed',
                message: error.message,
                duration: (Date.now() - startTime) / 1000 + 's',
                request_body_example: {
                    url: 'https://example.com',
                    viewport: CONFIG.DEFAULT_VIEWPORT
                }
            });
        }

        await cleanup();
    }
});

// Combined screenshot and page content endpoint
app.post('/combined', async(req, res) => {
    const startTime = Date.now();
    let browser = null;
    let page = null;

    // Check if too many concurrent requests
    if (activeRequests >= CONFIG.MAX_CONCURRENT_REQUESTS) {
        log(`Request rejected: too many concurrent requests (${activeRequests})`, 'warning');
        return res.status(503).json({
            error: 'Server busy',
            message: 'Too many concurrent requests. Please try again later.'
        });
    }

    activeRequests++;
    log(`Processing combined request [${activeRequests} active]`, 'info');

    // Check for empty request body
    if (!req.body || Object.keys(req.body).length === 0) {
        log('Request rejected: empty body', 'error');
        activeRequests--;
        return res.status(400).json({
            error: 'Request body is empty or missing'
        });
    }

    const cleanup = async() => {
        activeRequests--;
        if (page) {
            try {
                const closePromise = page.close();
                const timeoutPromise = new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Page close timeout')), CONFIG.PAGE_LIFECYCLE_TIMEOUT)
                );
                await Promise.race([closePromise, timeoutPromise]);
                pageCount = Math.max(0, pageCount - 1);
                log(`Page closed successfully [${pageCount} pages open]`, 'success');
            } catch (error) {
                log(`Failed to close page: ${error.message}`, 'warning');
            }
        }
    };

    try {
        const { url, viewport = {} } = req.body;

        if (!url) {
            log('Request rejected: missing URL', 'error');
            await cleanup();
            return res.status(400).json({
                error: 'URL is required in request body',
                received_body: req.body
            });
        }

        if (!isValidUrl(url)) {
            log(`Request rejected: invalid URL - ${url}`, 'error');
            await cleanup();
            return res.status(400).json({
                error: 'Invalid URL provided',
                message: 'URL must be a valid HTTP or HTTPS URL'
            });
        }

        log(`Processing combined request for: ${url}`, 'info');

        // Get browser instance
        browser = await getBrowser();
        log('Browser instance acquired', 'success');

        // Check if we've reached the maximum page count
        if (pageCount >= CONFIG.MAX_PAGES_PER_BROWSER) {
            log('Maximum page count reached, restarting browser', 'warning');
            try {
                const oldBrowser = browserInstance;
                browserInstance = null;
                await oldBrowser.close();
                browser = await getBrowser();
                log('Browser restarted successfully', 'success');
            } catch (error) {
                log(`Error during browser restart: ${error.message}`, 'error');
            }
        }

        // Create new page with timeout
        const createPagePromise = browser.newPage();
        const pageTimeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Page creation timeout')), CONFIG.PAGE_LIFECYCLE_TIMEOUT)
        );

        page = await Promise.race([createPagePromise, pageTimeoutPromise]);
        pageCount++;
        log(`New page created [${pageCount} pages open]`, 'success');

        // Configure page settings
        await page.setUserAgent(CONFIG.USER_AGENT);
        await page.setDefaultNavigationTimeout(CONFIG.NAVIGATION_TIMEOUT);
        await page.setViewport({
            width: Math.min(viewport.width || CONFIG.DEFAULT_VIEWPORT.width, 1920),
            height: Math.min(viewport.height || CONFIG.DEFAULT_VIEWPORT.height, 1080),
            deviceScaleFactor: 1
        });

        // Block unnecessary resource types
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const resourceType = request.resourceType();
            if (['media', 'font', 'websocket'].includes(resourceType)) {
                request.abort();
            } else {
                request.continue();
            }
        });

        // Navigate to URL
        log(`Navigating to ${url}`, 'info');
        try {
            const normalizedUrl = url.includes('://') ? url : `http://${url}`;
            await page.goto(normalizedUrl, {
                waitUntil: 'networkidle2',
                timeout: CONFIG.NAVIGATION_TIMEOUT
            });
            log('Navigation completed', 'success');
        } catch (navError) {
            log(`Navigation warning: ${navError.message}`, 'warning');
        }

        // Wait for additional time
        await new Promise(resolve => setTimeout(resolve, CONFIG.WAIT_AFTER_LOAD));

        // Get page content
        log('Getting page content', 'info');
        const content = await page.content();
        log('Content retrieved successfully', 'success');

        // Take screenshot
        log('Taking screenshot', 'info');
        const screenshot = await page.screenshot({
            type: 'jpeg',
            quality: Math.min(Math.max(viewport.quality || CONFIG.DEFAULT_VIEWPORT.quality, 40), 90),
            fullPage: !!viewport.fullPage,
            timeout: CONFIG.NAVIGATION_TIMEOUT
        });
        log('Screenshot captured successfully', 'success');

        const duration = (Date.now() - startTime) / 1000;
        log(`Combined request successful for ${url} (${duration}s)`, 'success');

        res.json({
            content: content,
            screenshot: screenshot.toString('base64')
        });

        await cleanup();
    } catch (error) {
        const duration = (Date.now() - startTime) / 1000;
        log(`Combined request failed: ${error.message} (${duration}s)`, 'error');

        if (!res.headersSent) {
            res.status(500).json({
                error: 'Request failed',
                message: error.message,
                duration: `${duration}s`
            });
        }

        await cleanup();
    }
});

// Status endpoint
app.get('/status', async(req, res) => {
    try {
        const memoryUsage = process.memoryUsage();
        const status = {
            status: 'ok',
            time: new Date().toISOString(),
            browser: browserInstance ? 'connected' : 'disconnected',
            browser_uptime: browserStartTime ? `${Math.round((Date.now() - browserStartTime) / 1000)}s` : 'N/A',
            active_pages: pageCount,
            active_requests: activeRequests,
            server_uptime: `${Math.round(process.uptime())}s`,
            memory: {
                rss_mb: Math.round(memoryUsage.rss / 1024 / 1024),
                heap_total_mb: Math.round(memoryUsage.heapTotal / 1024 / 1024),
                heap_used_mb: Math.round(memoryUsage.heapUsed / 1024 / 1024)
            },
            network: getNetworkInfo(),
            config: {
                timeout: CONFIG.TIMEOUT,
                navigation_timeout: CONFIG.NAVIGATION_TIMEOUT,
                default_viewport: CONFIG.DEFAULT_VIEWPORT,
                max_concurrent_requests: CONFIG.MAX_CONCURRENT_REQUESTS,
                rate_limits: {
                    window: `${CONFIG.RATE_LIMIT_WINDOW_MS/1000}s`,
                    max_requests: CONFIG.RATE_LIMIT_MAX_REQUESTS
                }
            }
        };

        res.json(status);
        log('Status check completed', 'success');
    } catch (error) {
        log(`Status check failed: ${error.message}`, 'error');
        res.status(500).json({
            error: 'Status check failed'
        });
    }
});

// Health check endpoint (lightweight version of status)
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        time: new Date().toISOString()
    });
});

// Start server
app.listen(CONFIG.PORT, CONFIG.HOST, () => {
    log(`Screenshot service running on ${CONFIG.HOST}:${CONFIG.PORT}`, 'success');

    // Display access URLs
    if (CONFIG.HOST === '0.0.0.0') {
        const networks = getNetworkInfo();
        log(`Local access: http://127.0.0.1:${CONFIG.PORT}`);
        networks.forEach(net => {
            log(`Network access: http://${net.address}:${CONFIG.PORT} (${net.interface})`);
        });
    } else {
        log(`Access: http://127.0.0.1:${CONFIG.PORT}`);
    }
});

// Graceful shutdown handler
process.on('SIGINT', async() => {
    log('Shutting down gracefully...');
    if (browserInstance) {
        try {
            await browserInstance.close();
            log('Browser instance closed successfully', 'success');
        } catch (error) {
            log(`Failed to close browser: ${error.message}`, 'error');
        }
    }
    process.exit(0);
});

// Error handlers
process.on('uncaughtException', (error) => {
    log(`Uncaught Exception: ${error.message}`, 'error');
    console.error(error.stack);
    // Keep running despite errors, but log them
});

process.on('unhandledRejection', (reason, promise) => {
    log(`Unhandled Rejection at: ${promise}, reason: ${reason}`, 'error');
    // Keep running despite rejected promises, but log them
});
