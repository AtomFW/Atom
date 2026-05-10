class OpenAIClient {
    /**
     * @param {string} apiKey - Yours key API OpenAI
     * @param {string} model - Default model (np. 'gpt-4o' lub 'gpt-3.5-turbo')
     */
    constructor(apiKey, model = 'gpt-4o') {
        this.apiKey = apiKey;
        this.model = model;
        this.apiUrl = 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Root method
     * @param {string} prompt - Body answer
     * @param {Object} options - Optional parameters (temperature, max_tokens itp.)
     */
    async ask(prompt, options = {}) {
        const messages = [
            { role: 'user', content: prompt }
        ];
        return this.chat(messages, options);
    }

    /**
     * Method to send chat messages
     * @param {Array} messages - Table od object {role: 'user'|'assistant'|'system', content: '...'}
     * @param {Object} options - Optional parameters
     */
    async chat(messages, options = {}) {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.apiKey}`
                },
                body: JSON.stringify({
                    model: this.model,
                    messages: messages,
                    temperature: options.temperature ?? 0.7,
                    max_tokens: options.max_tokens ?? 1000,
                    ...options
                })
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(`OpenAI Error: ${errorData.error?.message || response.statusText}`);
            }

            const data = await response.json();
            
            return {
                text: data.choices[0].message.content,
                usage: data.usage,
                raw: data
            };

        } catch (error) {
            console.error("Error when sending OpenAI request:", error);
            throw error;
        }
    }
}