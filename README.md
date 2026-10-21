# GenCC Submission Portal

[![Tests](https://github.com/GeneCurationCoalition/gencc-sub/workflows/Tests/badge.svg)](https://github.com/GeneCurationCoalition/gencc-sub/actions)
[![Laravel](https://img.shields.io/badge/Laravel-10.x-FF2D20?logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)](https://www.php.net/)
[![Vue.js](https://img.shields.io/badge/Vue.js-3.x-4FC08D?logo=vue.js)](https://vuejs.org/)

A web application for managing gene-disease relationship submissions to the [Gene Curation Coalition (GenCC)](https://thegencc.org/). This portal processes, validates, and curates submissions from member organizations, integrating data from multiple disease ontologies (MONDO, OMIM, Orphanet) and gene databases (HGNC).

## Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)
- [Testing](#testing)
- [Data Management](#data-management)
- [API](#api)
- [Deployment](#deployment)
- [Contributing](#contributing)
- [License](#license)

## Features

### Submission Management
- **Multi-format submission ingestion**: Excel file uploads and REST API submissions
- **Real-time validation**: Validate gene-disease relationships against HGNC, MONDO, OMIM, and Orphanet
- **State-based workflow**: Track submissions through draft → submitted → published lifecycle
- **Batch operations**: Process multiple submissions as jobs with granular error handling
- **Version control**: Support for new submissions, republishing, and unpublishing

### Data Integration
- **Disease ontology integration**:
  - MONDO (30,000+ terms) with deprecation tracking
  - OMIM (11,000+ diseases) with status monitoring
  - Orphanet (11,000+ rare diseases)
- **Gene validation**: HGNC gene symbol and ID verification
- **Cross-referencing**: Automatic mapping between disease ontologies
- **Source-specific deprecation**: Independent deprecation status for each ontology

### Curation Tools
- **Interactive dashboard**: Real-time overview of submission status and errors
- **Inline editing**: Modify submission details with validation
- **PubMed integration**: Automatic article metadata fetching for PMIDs
- **Evidence tracking**: Link submissions to supporting publications
- **Error reporting**: Detailed validation errors with suggestions

### Publishing Workflow
- **Staging system**: Review submissions before publication
- **Bulk publishing**: Publish entire jobs with validation checks
- **Search integration**: Automatic indexing to gencc-search service
- **Audit trail**: Complete history of changes and actions

## Technology Stack

### Backend
- **Framework**: Laravel 10 (PHP 8.1+)
- **Authentication**: Laravel Jetstream with Sanctum
- **Database**: MySQL/MariaDB
- **Permissions**: Spatie Laravel Permission
- **Data Import/Export**: Maatwebsite Excel

### Frontend
- **Framework**: Vue 3 with Composition API
- **SSR**: Inertia.js
- **UI Components**: PrimeVue
- **Styling**: Tailwind CSS
- **Build Tool**: Vite

### Infrastructure
- **Real-time Updates**: Ably broadcasting (configurable)
- **Process Management**: PM2 (optional)
- **Testing**: PHPUnit + Pest
- **CI/CD**: GitHub Actions

## Prerequisites

- **PHP**: 8.1 or higher
- **Composer**: 2.x
- **Node.js**: 22.x or higher (LTS)
- **NPM**: 10.x or higher
- **Database**: MySQL 8.0+ or MariaDB 10.3+

### PHP Extensions Required
```
dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql,
bcmath, soap, intl, gd, exif, iconv
```

## Installation

### 1. Clone the Repository
```bash
git clone https://github.com/GeneCurationCoalition/gencc-sub.git
cd gencc-sub
```

### 2. Install Dependencies
```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### 3. Environment Setup
```bash
# Copy environment file
cp .env.testing .env

# Generate application key
php artisan key:generate
```

### 4. Configure Database
Edit `.env` with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gencc_sub
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 5. Run Migrations
```bash
php artisan migrate
```

### 6. Import Reference Data
```bash
# Import disease ontologies (MONDO, OMIM, Orphanet)
php artisan update:diseases

# Import gene data from HGNC
php artisan import:genes

# Import lookup tables
php artisan import:tables
```

### 7. Build Frontend Assets
```bash
# Development
npm run dev

# Production
npm run build
```

## Configuration

### Broadcasting (Optional)
For real-time updates, configure Ably in `.env`:
```env
BROADCAST_DRIVER=ably
ABLY_KEY=your_ably_key
```

### External APIs
Configure API keys for external data sources:
```env
# PubMed E-utilities (optional API key for higher rate limits)
PUBMED_API_KEY=your_ncbi_api_key
```

## Development

### Option 1: Native Development Server

```bash
# Terminal 1: Start Laravel development server
php artisan serve

# Terminal 2: Start Vite development server with HMR
npm run dev
```

The application will be available at `http://localhost:8000`

### Option 2: Container-Based Development

Use Docker/Podman for a consistent development environment:

```bash
# Start development container (with hot reload)
podman-compose -f docker-compose.dev.yml up -d

# View logs
podman-compose -f docker-compose.dev.yml logs -f app

# Stop
podman-compose -f docker-compose.dev.yml down
```

The application will be available at `http://localhost:8001` (Laravel) and `http://localhost:5173` (Vite HMR).

**Note**: Requires local MySQL with `gencc_sub` database. Run `./scripts/backup/restore-db.sh` first to set up the database.

### Option 3: Production-Like Local Testing

Test the production container locally to catch permission issues before deployment:

```bash
# Build production image
podman-compose -f docker-compose.prod-test.yml build

# Run production-like container
podman-compose -f docker-compose.prod-test.yml up -d

# Test commands as www-data (how PHP-FPM runs)
podman-compose -f docker-compose.prod-test.yml exec app \
  su -s /bin/bash www-data -c 'php artisan clingen:sync -v'

# Interactive shell
podman-compose -f docker-compose.prod-test.yml exec app bash

# Stop
podman-compose -f docker-compose.prod-test.yml down
```

The application will be available at `http://localhost:8080`.

**Key differences from dev container**:

- App code is baked into image (root-owned, read-only)
- Only `storage/`, `bootstrap/cache/`, and `data/` are www-data writable
- Mimics production container behavior exactly

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Run static analysis
./vendor/bin/phpstan analyse
```

## Testing

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Suites
```bash
# Unit tests only
./vendor/bin/phpunit tests/Unit

# Feature tests only
./vendor/bin/phpunit tests/Feature

# Specific test class
./vendor/bin/phpunit --filter=DiseaseUpdateRefactoringTest
```

### Test Coverage
```bash
php artisan test --coverage
```

## Data Management

### Import Commands
```bash
# Import GenCC curated data
php artisan import:gencc

# Import ClinGen data
php artisan import:clingen

# Import submissions from Excel file
php artisan import:submission path/to/file.xlsx
```

### Update Commands
```bash
# Update disease information from all sources
php artisan update:diseases

# Update specific data sources
php artisan update:genes          # HGNC gene data
php artisan update:omim           # OMIM disease mappings
php artisan update:pubmed         # PubMed article metadata

# Update search index
php artisan update:search-submissions
```

### Publishing
```bash
# Publish staged submissions
php artisan run:publish

# Generate metrics reports
php artisan run:metrics
```

## API

### Authentication
The API uses Laravel Sanctum for authentication. Include your API token in requests:
```bash
Authorization: Bearer {your-api-token}
```

### Endpoints

#### Submit New Data
```http
POST /api/submit?action=check
Content-Type: application/json

{
  "gene": "HGNC:1234",
  "disease": "MONDO:0005148",
  "classification": "Definitive",
  "moi": "HP:0000006",
  "pmids": ["12345678"],
  "report_date": "2024-01-15",
  "report_url": "https://example.com/report"
}
```

#### Query Submission
```http
GET /api/submit/{sgc_id}
```

#### Query Job Status
```http
GET /api/query/job/{job_id}
```

### Response Format
```json
{
  "status": "success",
  "data": {
    "sid": "SGC-100001",
    "status": "published",
    "gene": {
      "hgnc_id": "HGNC:1234",
      "symbol": "BRCA1"
    },
    "disease": {
      "curie": "MONDO:0005148",
      "name": "Type 2 diabetes mellitus"
    }
  }
}
```

## Deployment

See [DEPLOYMENT.md](docs/DEPLOYMENT.md) for detailed production deployment instructions.

### Quick Deploy
```bash
# Pull latest changes
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm ci

# Build assets
npm run build

# Run migrations
php artisan migrate --force

# Clear and cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
php artisan queue:restart
```

## Project Structure

```
gencc-sub/
├── app/
│   ├── Console/Commands/     # Artisan commands
│   ├── Http/Controllers/     # Web and API controllers
│   ├── Models/               # Eloquent models
│   └── Services/             # Business logic services
├── resources/
│   ├── js/
│   │   ├── Components/       # Vue components
│   │   ├── Pages/            # Inertia pages
│   │   └── app.js            # Frontend entry point
│   └── views/                # Blade templates
├── routes/
│   ├── api.php               # API routes
│   └── web.php               # Web routes
├── database/
│   ├── migrations/           # Database migrations
│   └── factories/            # Model factories
├── tests/
│   ├── Feature/              # Feature tests
│   └── Unit/                 # Unit tests
└── storage/
    └── app/data/             # Cached external data
```

## Key Models

- **Job**: Groups one or more submissions together
- **Submission**: Individual gene-disease curation record
- **Gene**: HGNC gene data
- **Disease**: Disease ontology data (MONDO, OMIM, Orphanet)
- **Classification**: Curation classification levels
- **Inheritance**: Mode of inheritance (HP terms)
- **Pubmed**: PubMed article metadata

## Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Coding Standards
- Follow PSR-12 coding style
- Write tests for new features
- Update documentation as needed
- Use conventional commit messages

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Acknowledgments

- **GenCC** - Gene Curation Coalition
- **MONDO** - Monarch Disease Ontology
- **OMIM** - Online Mendelian Inheritance in Man
- **Orphanet** - Portal for rare diseases
- **HGNC** - HUGO Gene Nomenclature Committee

## Support

For issues and questions:
- **GitHub Issues**: https://github.com/GeneCurationCoalition/gencc-sub/issues
- **Documentation**: See `CLAUDE.md` and `docs/DEVELOPMENT.md` for additional guidance

---

Built with ❤️ for the gene curation community
