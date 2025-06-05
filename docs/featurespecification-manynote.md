# **Feature Specification: Google Drive Integration**

## **1\. Summary**

This feature will integrate Google Drive as the exclusive storage backend for Many Notes. All user vaults, notes, and folders will be stored within the user's hidden Google Drive **Application Data folder**, which is only accessible by the application. This ensures data is securely backed up to the cloud and synchronized across devices without cluttering the user's visible Drive files. An "Export All" feature will be provided to allow users to retrieve all their data in a single archive.

## **2\. Goals**

* Require users to authenticate with their Google account using OAuth 2.0 upon first use or account creation.  
* Utilize the Google Drive Application Data folder for all storage operations.  
* Implement full CRUD (Create, Read, Update, Delete) functionality for notes and vaults stored on Google Drive.  
* Provide a comprehensive "Export All" function for users to download their entire data set.  
* Ensure a seamless and responsive user experience for all file operations.

## **3\. Target Users**

* Registered User

## **4\. User Stories**

* **Story 1: Connecting Google Drive**  
  * **As a** new Registered User,  
  * **I want to** connect my Google Drive account when I sign up,  
  * **so that** the application can save my notes securely in the cloud.  
  * **Acceptance Criteria**:  
    * The registration or initial login process includes a mandatory step to connect a Google account.  
    * The process uses a standard Google OAuth 2.0 flow.  
    * The application requests permission to access its private Application Data folder (drive.appdata).  
    * After successful authentication, the application securely stores the necessary tokens to access Google Drive.  
    * A confirmation message is displayed, and I am taken to the main application interface.  
* **Story 2: Creating a Vault in Google Drive**  
  * **As a** Registered User,  
  * **I want to** create a new vault,  
  * **so that** a corresponding folder is automatically created in my hidden Google Drive App Data folder to store my notes.  
  * **Acceptance Criteria**:  
    * When I create a new vault, a new folder with the vault's name is created within the app's private folder in my Google Drive.  
    * The new vault appears in my list of vaults in the UI.  
    * The application correctly handles cases where a folder with the same name already exists.  
* **Story 3: Managing Notes in a Google Drive Vault**  
  * **As a** Registered User,  
  * **I want to** create, edit, view, and delete notes and folders within a vault,  
  * **so that** all changes are saved directly to my Google Drive.  
  * **Acceptance Criteria**:  
    * Creating a new note creates a corresponding .md file in the correct vault folder in Google Drive's App Data space.  
    * Opening a note reads its content from the corresponding file in Google Drive.  
    * Saving a note updates the content of the corresponding file in Google Drive.  
    * Deleting a note or folder removes the corresponding file or folder from Google Drive.  
* **Story 4: Exporting All Data**  
  * **As a** Registered User,  
  * **I want to** have an "Export All" option,  
  * **so that** I can download a complete backup of all my vaults and notes at any time.  
  * **Acceptance Criteria**:  
    * There is a clearly accessible "Export All" button in the application settings.  
    * Clicking the button retrieves all files and folders from the Google Drive App Data folder.  
    * The application packages all retrieved data into a single, structured .zip archive for download.  
    * The downloaded archive maintains the user's vault and folder hierarchy.

## **5\. Functional Requirements**

* **FR-FS-001**: The system **must** require users to authenticate with Google using OAuth 2.0. (Priority: **must-have**)  
* **FR-FS-002**: The system **must** use the Google Drive API and the drive.appdata scope for all file and folder operations. (Priority: **must-have**)  
* **FR-FS-003**: The system **must** map vault operations (create, rename, delete) to folder operations within the Google Drive App Data folder. (Priority: **must-have**)  
* **FR-FS-004**: The system **must** map note operations (create, read, update, delete) to file operations within the corresponding vault folder. (Priority: **must-have**)  
* **FR-FS-005**: The system **must** securely store and manage Google API access and refresh tokens. (Priority: **must-have**)  
* **FR-FS-006**: The system **must** provide an "Export All" function that packages all of the user's data from the App Data folder into a downloadable zip file. (Priority: **must-have**)

## **6\. Non-Functional Requirements**

* **NFR-FS-001** (Performance): API calls to Google Drive should be optimized to minimize latency. Operations should feel responsive, ideally under 500ms.  
* **NFR-FS-002** (Security): All communication with the Google Drive API must be over HTTPS. OAuth tokens must be encrypted at rest.  
* **NFR-FS-003** (Usability): The initial one-time authentication process should be simple and straightforward for the user.  
* **NFR-FS-004** (Reliability): The integration must gracefully handle Google Drive API errors (e.g., rate limiting, network issues, revoked permissions) and provide clear feedback.

## **7\. Design & Technical Considerations**

* **UI/UX**:  
  * The primary UI change will be in the onboarding/authentication flow, making the Google login prominent and required.  
  * An "Export All" button will be added to the main settings or user profile menu.  
  * Since all vaults are on Google Drive, no special icons are needed to differentiate them.  
* **Technical Approach**:  
  * Utilize **Laravel Socialite** for the Google OAuth 2.0 flow, requesting the drive.appdata scope.  
  * Implement a custom **Laravel Filesystem driver for Google Drive**, configured to operate within the App Data folder.  
  * All application logic will now exclusively use this Google Drive filesystem disk.  
  * The "Export All" function will recursively list all files and folders from the root of the App Data space, stream them into a zip archive, and send it to the user.

## **8\. Data Model / Database Schema Changes**

* **Table:** users  
* **New Columns:**  
  * google\_access\_token (TEXT, encrypted): To store the user's access token.  
  * google\_refresh\_token (TEXT, encrypted, nullable): To store the token for renewing access without requiring the user to log in again.  
  * google\_token\_expires\_at (TIMESTAMP, nullable): To track token expiration.

## **9\. Detailed Task Breakdown / Implementation Plan**

* **Phase 1: Authentication & Configuration**  
  * Task 1.1: Create a new project in the Google Cloud Console.  
  * Task 1.2: Configure the OAuth consent screen with the application's details.  
  * Task 1.3: Generate OAuth 2.0 Client ID and Client Secret credentials.  
  * Task 1.4: Add the new credentials to the project's .env and config/services.php files.  
* **Phase 2: Backend Development**  
  * Task 2.1: Add the google/apiclient library to composer.json.  
  * Task 2.2: Create a database migration to add google\_access\_token, google\_refresh\_token, and google\_token\_expires\_at columns to the users table.  
  * Task 2.3: Implement a custom Laravel Filesystem driver (GoogleDriveAdapter) that uses the Google Drive API and specifically targets the appDataFolder space.  
  * Task 2.4: Register the new filesystem driver in a service provider.  
  * Task 2.5: Refactor all existing file operation Actions (e.g., CreateVault, CreateVaultNode, DeleteVaultNode) to exclusively use the new Google Drive filesystem disk.  
* **Phase 3: User-Facing Features**  
  * Task 3.1: Implement the required Google Sign-In flow as part of the user registration/login process.  
  * Task 3.2: Develop the "Export All" functionality, including the logic to recursively fetch files and package them into a zip archive.  
* **Phase 4: Testing & Quality Assurance**  
  * Task 4.1: Write unit tests for the GoogleDriveAdapter.  
  * Task 4.2: Write feature tests for the complete user journey: authentication \-\> vault creation \-\> note management \-\> export.

## **10\. Detailed Error Handling Plan**

* **Scenario:** User revokes app permissions from their Google Account.  
  * **API Response:** 401 Unauthorized or invalid\_grant.  
  * **Application Action:** Log the user out, invalidate their stored tokens, and redirect them to the login page with a message: "Your connection to Google Drive has expired. Please sign in again."  
* **Scenario:** Google Drive API is temporarily unavailable.  
  * **API Response:** 5xx Server Error.  
  * **Application Action:** Display a temporary toast notification: "Could not connect to Google Drive at the moment. Please try again in a few minutes."  
* **Scenario:** User's Google Drive storage is full.  
  * **API Response:** 403 Forbidden with a "storageQuotaExceeded" reason.  
  * **Application Action:** Prevent new file/vault creation and display an error message: "Your Google Drive storage is full. Please free up space and try again."

## **11\. Open Issues & Questions**

* What is the strategy for handling users who revoke Google Drive access from their Google account settings? The application should detect this (e.g., via a 401 Unauthorized API response) and prompt the user to re-authenticate.