<?php

declare(strict_types=1);

namespace SharpAPI\ContentSummarize;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use SharpAPI\Core\Client\SharpApiClient;

/**
 * @api
 */
class ContentSummarizeService extends SharpApiClient
{
    /**
     * Initializes a new instance of the class.
     *
     * @throws InvalidArgumentException if the API key is empty.
     */
    public function __construct()
    {
        parent::__construct(config('sharpapi-content-summarize.api_key'));
        $this->setApiBaseUrl(
            config(
                'sharpapi-content-summarize.base_url',
                'https://sharpapi.com/api/v1'
            )
        );
        $this->setApiJobStatusPollingInterval(
            (int) config(
                'sharpapi-content-summarize.api_job_status_polling_interval',
                5)
        );
        $this->setApiJobStatusPollingWait(
            (int) config(
                'sharpapi-content-summarize.api_job_status_polling_wait',
                180)
        );
        $this->setUserAgent('SharpAPILaravelContentSummarize/1.0.0');
    }

    /**
     * Summarizes the provided text while maintaining its key points.
     * Perfect for creating concise versions of longer content.
     * 
     * Only the `content` parameter is required. You can define the output language,
     * maximum character length, and tone of voice. Additional instructions
     * on how to process the text can be provided in the context parameter.
     * Please keep in mind that `max_length` serves as a strong suggestion
     * for the Language Model, rather than a strict requirement,
     * to maintain the general sense of the outcome.
     *
     * @param string $text The text to summarize
     * @param string|null $language The language for the summarized text (optional)
     * @param int|null $maxLength Maximum length of the summarized text (optional)
     * @param string|null $voiceTone The tone of voice for the summarized text (optional)
     * @param string|null $context Additional context for better summarization (optional)
     * @return string The summarized text or an error message
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function summarize(
        string $text,
        ?string $language = null,
        ?int $maxLength = null,
        ?string $voiceTone = null,
        ?string $context = null
    ): string {
        $response = $this->makeRequest(
            'POST',
            '/content/summarize',
            [
                'content' => $text,
                'language' => $language,
                'max_length' => $maxLength,
                'voice_tone' => $voiceTone,
                'context' => $context,
            ]
        );

        return $this->parseStatusUrl($response);
    }
}