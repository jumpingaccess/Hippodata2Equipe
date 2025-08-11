# Equipe Hippodata Import Extension (Rails)

This Rails application provides an import interface between Hippodata (FEI event data) and the Equipe competition management system.

## Features

- Search and fetch FEI event data from Hippodata with event logo display
- Selective import of:
  - Competition classes with FEI articles
  - Startlists (including team competitions)
  - Results with team-specific handling
- Advanced team competition support:
  - Automatic team creation for nations with 3+ riders
  - Skip rounds management for team competitions
  - Team flags and exclusion handling
- Support for riders and horses without FEI IDs (temporary ID generation)
- Real-time import progress tracking with animated spinners
- Duplicate detection to avoid re-importing existing data
- Visual indicators for team competitions
- Version display

## Requirements

- Ruby 3.2.0
- Rails 7.0.0
- PostgreSQL
- Valid Hippodata API credentials
- Valid Equipe API credentials

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd equipe-hippodata-extension
```

2. Install dependencies:
```bash
bundle install
```

3. Set up environment variables:
```bash
cp .env.example .env
```

Edit `.env` and add your credentials:
- `EQUIPE_SECRET`: Your Equipe JWT secret key
- `HIPPODATA_BEARER`: Your Hippodata API bearer token
- `DEBUG`: Set to 1 to enable debug logging
- `VERSION`: Application version number

4. Set up the database:
```bash
rails db:create
rails db:migrate
```

5. Add required assets:
- Place `R.webp` (team indicator image) in `app/assets/images/`

6. Start the server:
```bash
rails server
```

## Usage

### As an Equipe Extension

This application is designed to be accessed as an extension from within Equipe:

1. The application receives a JWT token from Equipe containing:
   - Meeting URL
   - API Key
   - Display mode (modal/browser)

2. Users can:
   - Search for FEI events by ID
   - View event details with logo
   - Select which classes to import
   - Choose what data to import (class definitions, startlists, results)
   - Mark classes as team competitions
   - Specify FEI articles for each class
   - Auto-detect team competitions based on class name

### Features Details

#### Team Competition Detection
The system automatically detects team competitions based on keywords in the class name:
- team
- lln
- nations cup
- equipe
- teams

#### Temporary ID Generation
For riders and horses without FEI IDs, the system generates temporary identifiers:
- Riders: `TEMP_R_[EventID]_[MD5Hash]`
- Horses: `TEMP_H_[EventID]_[MD5Hash]`

#### Skip Rounds Management
For team competitions, the system automatically handles:
- Detection of riders excluded from team rounds
- Assignment of `skip_rounds` arrays for proper result handling
- Analysis of nation-based team compositions

### API Endpoints

The application provides the following API endpoints:

- `POST /api/v1/imports/fetch_event_info` - Fetch event information from Hippodata
- `POST /api/v1/imports/get_imported_status` - Check what has already been imported
- `POST /api/v1/imports/import_selected` - Import selected classes
- `POST /api/v1/imports/import_startlists` - Process and import startlists
- `POST /api/v1/imports/import_results` - Process and import results
- `POST /api/v1/imports/send_batch_to_equipe` - Proxy for sending data to Equipe

## Architecture

### Services

- **HippodataService**: Handles all communication with the Hippodata API
- **ImportService**: Manages the import process, data transformation, and batching
  - Handles temporary ID generation
  - Manages team detection and creation
  - Processes skip rounds for team competitions

### Controllers

- **ExtensionController**: Main entry point, handles JWT decoding and UI rendering
- **Api::V1::ImportsController**: Handles all import-related API requests

### Key Features

1. **Enhanced Team Competition Support**:
   - Automatically creates teams for nations with 3+ riders
   - Manages team assignments in startlists
   - Handles team-specific result flags
   - Skip rounds detection and assignment
   - Visual team indicators in UI

2. **Batch Processing**:
   - Consolidates duplicate riders and horses across competitions
   - Imports data in the correct order (clubs → people → horses → teams → starts → results)
   - Uses transactions to ensure data consistency
   - Handles temporary IDs for missing FEI IDs

3. **User Interface Enhancements**:
   - Event logo display (JPG/PNG support)
   - Animated spinners during processing
   - Progress tracking with visual feedback
   - Version badge display
   - Auto-selection of team competitions

4. **Error Handling**:
   - Graceful handling of API failures
   - Detailed error reporting in the UI
   - Rollback support for failed imports
   - Debug mode for troubleshooting

## Development

### Running Tests

```bash
bundle exec rspec
```

### Debug Mode

Set `DEBUG=1` in your `.env` file to enable detailed logging. This will:
- Log temporary ID generation
- Show detailed team competition analysis
- Display skip rounds calculations
- Track API requests and responses

### Code Structure

```
app/
├── controllers/
│   ├── application_controller.rb
│   ├── extension_controller.rb
│   └── api/
│       └── v1/
│           ├── base_controller.rb
│           └── imports_controller.rb
├── services/
│   ├── hippodata_service.rb
│   └── import_service.rb
├── views/
│   └── extension/
│       └── index.html.erb
├── assets/
│   ├── stylesheets/
│   │   └── custom.css
│   └── images/
│       └── R.webp
└── config/
    └── initializers/
        └── debug_mode.rb
```

## Security

- JWT tokens are validated using the configured secret
- CORS is configured to only accept requests from `https://app.equipe.com`
- All API endpoints require valid authentication
- Sensitive credentials are stored in environment variables
- Temporary IDs ensure no FEI ID exposure for non-FEI participants

## Data Handling

### Class Import
- Extracts time from DATETIME field
- Assigns incremental order numbers
- Handles FEI articles and team flags

### Startlist Import
- Creates temporary IDs for missing FEI IDs
- Handles birth year validation for horses
- Manages team creation for qualifying nations
- Supports national club text for non-international competitions

### Results Import
- Analyzes team participation by nation
- Automatically assigns skip_rounds for excluded riders
- Handles all special statuses (retired, eliminated, etc.)
- Manages team-specific result flags

## Version History

Check the `VERSION` environment variable for the current version. The version is displayed in the UI header.

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

For any questions or issues:
- Technical Support: Check debug logs first
- Application logs: Located in `log/development.log` or `log/production.log`
- Debug mode: Enable for detailed operation traces
- Common issues:
  - Missing FEI IDs: System will generate temporary IDs automatically
  - Team detection: Check class names for team keywords
  - Skip rounds: Verify nation groupings in debug mode

