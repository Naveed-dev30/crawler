<?php
// Variables
return [
    "creatorName" => "FlutterArc",
    "creatorUrl" => "https://flutterarc.com",
    "templateName" => env("APP_NAME"),
    "templateSuffix" => "App",
    "templateVersion" => "1.0.0",
    "templateFree" => false,
    "templateDescription" => "",
    "templateKeyword" => "",
    "licenseUrl" => "",
    "livePreview" => "",
    "productPage" => "",
    "support" => "",
    "moreThemes" => "",
    "documentation" => "",
    "generator" => "",
    "changelog" => "",
    "repository" => "",
    "gitAuthor" => "FlutterArc",
    "gitRepo" => "",
    "facebookUrl" => "",
    "twitterUrl" => "",
    "githubUrl" => "",
    "dribbbleUrl" => "",
    "instagramUrl" => "",
    "flKey" => env("FL_ACCESS"),
    "flUserId" => env("FL_USER_ID"),
    // Freelancer API base — point at https://www.freelancer-sandbox.com in dev.
    "flBase" => env("FL_BASE_URL", "https://www.freelancer.com"),
    // Dev-only: fabricate Freelancer chat data locally (no network in or out).
    "flFake" => env("FL_FAKE", false),
    // Upwork GraphQL API (Opportunities → Upwork tab). Values supplied via .env.
    "upworkBase"         => env("UPWORK_BASE_URL", "https://api.upwork.com/graphql"),
    "upworkTokenUrl"     => env("UPWORK_OAUTH_TOKEN_URL", "https://www.upwork.com/api/v3/oauth2/token"),
    "upworkClientId"     => env("UPWORK_CLIENT_ID"),
    "upworkClientSecret" => env("UPWORK_CLIENT_SECRET"),
    "upworkAccessToken"  => env("UPWORK_ACCESS_TOKEN"),
    "upworkRefreshToken" => env("UPWORK_REFRESH_TOKEN"),
    "upworkTenantId"     => env("UPWORK_TENANT_ID"),
    "openAIKey" => env('OPENAI_API_KEY'),
    // Global kill switch for AI auto-replies. Off by default: when false, no
    // thread ever gets an automatic AI answer, whatever the per-user schedule
    // or manual toggle says. Set AI_AUTO_REPLY_ENABLED=true to reactivate.
    "aiAutoReplyEnabled" => env('AI_AUTO_REPLY_ENABLED', false),
    "gamificationIngestToken" => env('GAMIFICATION_INGEST_TOKEN'),
    "ingestToken" => env('INGEST_TOKEN', env('GAMIFICATION_INGEST_TOKEN')),
];
