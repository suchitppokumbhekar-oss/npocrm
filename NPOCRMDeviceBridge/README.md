# NPO CRM Android Device Bridge

This companion is the native side of NPO CRM Android device lead capture.

## What it provides
- Phone Contacts: opens the native Android phone-contact picker and sends the selected name/number back to the authenticated NPO CRM web app.
- Recent Calls: requests `READ_CALL_LOG`, displays recent Android call-log entries, and sends the selected name/number back to the authenticated NPO CRM web app.
- Android Sharesheet target: accepts `text/plain`, `text/vcard`, and `text/x-vcard`, including contact cards shared from compatible apps such as WhatsApp, then opens NPO CRM Add Lead with name/phone/email prefilled.
- Custom scheme `npocrm://phone-contacts` is used by the CRM PWA phone-contact button when Chrome Contact Picker is unavailable.
- Custom scheme `npocrm://recent-calls` is used by the CRM PWA recent-calls button.
- Custom scheme `npocrm://whatsapp-contact` opens WhatsApp and instructs the user to use WhatsApp Contact → Share contact → NPO CRM. The CRM then receives the contact through the existing Android Sharesheet target.
- All device flows return to the existing Add Lead form for review; the bridge never creates a lead automatically.

## Build
Open this directory in Android Studio and build/install the debug APK on the Android device. The project uses Android Gradle Plugin 8.6.1 and compileSdk 35.

## Call-log permission note
Android classifies READ_CALL_LOG as a restricted/dangerous permission. Distribution through Google Play may require the app to satisfy Android's default-handler/policy requirements; sideloading/testing is separate. See Android's current permission guidance.
