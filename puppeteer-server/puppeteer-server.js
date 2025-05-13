const express = require('express');
const puppeteer = require('puppeteer');
const app = express();

app.use(express.json());

app.post('/screenshot', async (req, res) => {
    const { url, viewport } = req.body;
    
    try {
        const browser = await puppeteer.launch({
            args: ['--no-sandbox', '--disable-setuid-sandbox']
        });
        const page = await browser.newPage();
        
        // Set viewport
        await page.setViewport({
            width: parseInt(viewport.width) || 1280,
            height: parseInt(viewport.height) || 720
        });

        await page.goto(url, {
            waitUntil: 'networkidle0',
            timeout: 60000
        });

        // Wait extra time for dynamic content
        await page.waitForTimeout(5000);

        // Take screenshot
        const screenshot = await page.screenshot({
            type: 'jpeg',
            quality: parseInt(viewport.quality) || 80
        });

        await browser.close();

        res.contentType('image/jpeg');
        res.send(screenshot);
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Puppeteer server running on port ${PORT}`);
});
