<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Android notification channel prefix
    |--------------------------------------------------------------------------
    |
    | Every FCM push is stamped with a channel id derived from the recipient
    | device's stored sound preference: "<prefix>", "<prefix>_chime",
    | "<prefix>_ding", "<prefix>_pop", or "<prefix>_silent".
    |
    | MUST stay in lock-step with NotificationConstants.channelId in the Flutter
    | app (lib/core/notifications/notification_constants.dart). A channel's
    | sound is immutable on Android 8+, so a mismatch cannot be corrected at
    | runtime — the notification simply lands on a channel that does not exist.
    |
    */

    'android_channel_prefix' => env('PUSH_ANDROID_CHANNEL', 'alladin_notifications'),

    /*
    |--------------------------------------------------------------------------
    | Selectable sounds
    |--------------------------------------------------------------------------
    |
    | Accepted values for a device's `sound_key`. Mirrors the NotificationSound
    | enum in the Flutter app; the bundled assets are res/raw/<key>.wav on
    | Android and Runner/Sounds/<key>.wav on iOS.
    |
    */

    'sound_keys' => ['default', 'chime', 'ding', 'pop'],

    /*
    |--------------------------------------------------------------------------
    | Placeholder device tokens
    |--------------------------------------------------------------------------
    |
    | The app sends a sentinel string when it cannot obtain a real FCM token
    | (push permission denied, or an iOS simulator with no APNs token). These
    | are never stored: FCM rejects them as "not a valid FCM registration
    | token", which the dead-token sweep then clears, and the next login writes
    | the same junk straight back.
    |
    */

    'placeholder_tokens' => ['unavailable', 'null', 'none', 'undefined'],

    /*
    |--------------------------------------------------------------------------
    | Show chat messages in the in-app alerts list
    |--------------------------------------------------------------------------
    |
    | Off by default. Chat messages already have a first-class surface (the
    | chats list, with read state), so mirroring each one into Alerts turns it
    | into a second, worse inbox — and because the client pages alerts 50 at a
    | time, message rows would bury every assignment and escalation alert.
    |
    | When enabled, at most one unread row is kept per conversation (upserted
    | rather than appended), which caps the noise.
    |
    */

    'messages_in_alerts' => (bool) env('PUSH_MESSAGES_IN_ALERTS', false),

];
