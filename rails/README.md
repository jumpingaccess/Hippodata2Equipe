# Equipe Hippodata Import Extension (Rails)

This Rails application provides an import interface between Hippodata (FEI event data) and the Equipe competition management system.

## Features

- Search and fetch FEI event data from Hippodata
- Selective import of:
  - Competition classes
  - Startlists (including team competitions)
  - Results
- Support for team competitions with automatic team creation
- Real-time import progress tracking
- Duplicate detection to avoid re-importing existing data

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

4. Set up the database:
```bash
rails db:create
rails db:migrate
```

5. Start the server:
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
   - Select which classes to import
   - Choose what data to import (class definitions, startlists, results)
   - Mark classes as team competitions
   - Specify FEI articles for each class

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

### Controllers

- **ExtensionController**: Main entry point, handles JWT decoding and UI rendering
- **Api::V1::ImportsController**: Handles all import-related API requests

### Key Features

1. **Team Competition Support**:
   - Automatically creates teams for nations with 3+ riders
   - Manages team assignments in startlists
   - Handles team-specific result flags

2. **Batch Processing**:
   - Consolidates duplicate riders and horses across competitions
   - Imports data in the correct order (clubs → people → horses → teams → starts → results)
   - Uses transactions to ensure data consistency

3. **Error Handling**:
   - Graceful handling of API failures
   - Detailed error reporting in the UI
   - Rollback support for failed imports

## Development

### Running Tests

```bash
bundle exec rspec
```

### Debug Mode

Set `DEBUG=1` in your `.env` file to enable detailed logging.

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
└── assets/
    └── stylesheets/
        └── custom.css
```

## Security

- JWT tokens are validated using the configured secret
- CORS is configured to only accept requests from `https://app.equipe.com`
- All API endpoints require valid authentication
- Sensitive credentials are stored in environment variables

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

For any questions or issues:
- Jumpingaccess Support: info@jumpingaccess.com
- Server logs for PHP errors
- Debug mode to trace operations