# Extension Equipe - Synchronisation Hippodata

Cette extension permet de synchroniser les résultats de compétitions depuis l'API Hippodata vers Equipe.

## Installation

### 1. Prérequis

- PHP >= 7.4
- Composer
- Serveur web avec HTTPS (requis pour les cookies en iframe)
- Compte Equipe avec accès API
- Accès à l'API Hippodata avec token Bearer

### 2. Installation des dépendances

```bash
composer install
```

### 3. Configuration

1. Copier le fichier `.env.php.example` vers `.env.php`
```bash
cp .env.php.example .env.php
```

2. Éditer `.env.php` et ajouter vos clés :
   - `EQUIPE_SECRET` : Clé JWT fournie par Equipe
   - `HIPPODATA_BEARER` : Token Bearer pour l'API Hippodata

### 4. Structure des fichiers

```
/
├── index.php                 # Point d'entrée principal
├── composer.json            # Dépendances PHP
├── .env.php.example         # Template de configuration
├── .env.php                 # Configuration (à créer, ignoré par git)
├── config/
│   └── env.php             # Chargeur de configuration
└── vendor/                  # Dépendances (généré par composer)
```

## Configuration dans Equipe

### 1. Créer l'extension dans Equipe

1. Aller dans les paramètres d'organisation
2. Créer une nouvelle extension
3. Configurer :
   - **URL de base** : `https://votre-domaine.com/path-to-extension/`
   - **Secret JWT** : Générer et copier dans `.env.php`
   - **Type** : Modal/Browser et Webhook

### 2. Ajouter les actions

Créer une action webhook :
- **Nom** : `sync_from_hippodata`
- **Label** : "Synchroniser depuis Hippodata"
- **Contexte** : Competition

## Utilisation

### Mode interactif (Modal/Browser)

1. Dans Equipe, ouvrir une compétition
2. Cliquer sur l'action de l'extension
3. Dans la fenêtre qui s'ouvre, cliquer sur "Synchroniser les résultats"

### Mode webhook

L'extension peut être déclenchée automatiquement via webhook lors de certains événements.

## Fonctionnement

### 1. Récupération de l'ID FEI

L'extension cherche l'identifiant FEI de la compétition dans cet ordre :
1. Dans les custom fields : `custom_fields.fei_event_id`
2. Dans le `foreign_id` de la compétition
3. En parsant le nom de la compétition (recherche de "FEI ID: xxxxx")

### 2. Appel API Hippodata

L'extension appelle l'API Hippodata avec l'ID FEI pour récupérer les résultats.

### 3. Transformation des données

Les résultats Hippodata sont transformés au format Equipe :
- Mapping des cavaliers et chevaux via leur FEI ID
- Conversion des statuts (withdrawn, eliminated, etc.)
- Gestion des temps et pénalités par tour

### 4. Envoi vers Equipe

Les données sont envoyées via l'API Batch d'Equipe avec :
- Transaction UUID pour permettre l'annulation
- Mode `replace` pour remplacer tous les résultats existants
- Utilisation du `foreign_id` pour identifier uniquement les starts

## Sécurité

- Les tokens JWT sont vérifiés à chaque requête
- Les clés sont stockées hors du code source
- Support HTTPS requis pour les cookies en iframe
- Validation des données entrantes

## Dépannage

### L'extension ne se charge pas en modal

Vérifier :
- HTTPS activé
- Configuration des cookies dans `index.php`
- Headers X-Frame-Options configurés

### Erreur "No FEI Event ID found"

La compétition doit avoir un identifiant FEI. Vérifier :
- Le champ `foreign_id` de la compétition
- Les custom fields
- Le format du nom de la compétition

### Erreur d'authentification Hippodata

Vérifier :
- Le token Bearer dans `.env.php`
- La validité du token
- Les permissions sur l'API Hippodata

## Support

Pour toute question ou problème, contacter le support Equipe ou consulter la documentation API :
- [Documentation API Equipe](http://api-docs.equipe.com/)
- Documentation API Hippodata (selon votre accès)