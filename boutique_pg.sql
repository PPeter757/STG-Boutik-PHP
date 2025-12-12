-- boutique_pg.sql
-- PostgreSQL-ready SQL for database: gestion_boutique
-- Encoding: UTF8
-- This script creates schema, tables (in correct order), triggers, foreign keys, indexes
-- and inserts for roles, role_permissions and users (as requested).
-- Save this file as UTF-8 (no BOM) and run with psql:
-- Windows PowerShell example:
-- $env:PGPASSWORD = "<PASSWORD>"
-- psql -h <HOST> -U <USER> -d gestion_boutique -f boutique_postgres_ready.sql

SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;

-- =========================
-- RESET schema public
-- =========================
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public;
SET search_path TO public;

-- =========================
-- TABLES that other tables depend on (roles/role_permissions/users)
-- =========================

-- roles
DROP TABLE IF EXISTS role_permissions CASCADE;
DROP TABLE IF EXISTS roles CASCADE;
CREATE TABLE roles (
    role_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nom_role TEXT NOT NULL,
    description TEXT NOT NULL,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- role_permissions
CREATE TABLE role_permissions (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    role_id INTEGER NOT NULL,
    permission TEXT NOT NULL
);

-- users
DROP TABLE IF EXISTS users CASCADE;
CREATE TABLE users (
    user_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username TEXT NOT NULL,
    email TEXT NOT NULL,
    password TEXT NOT NULL,
    civilite TEXT NOT NULL,
    user_nom TEXT NOT NULL,
    user_prenom TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL,
    status_user_account TEXT NOT NULL,
    photo TEXT NOT NULL,
    role_id INTEGER,
    last_activity TIMESTAMP
);

-- =========================
-- Remaining tables (create in safe order)
-- =========================

-- clients
DROP TABLE IF EXISTS clients CASCADE;
CREATE TABLE clients (
    client_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nom TEXT,
    prenom TEXT,
    groupe TEXT,
    telephone TEXT UNIQUE,
    adresse TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- produits
DROP TABLE IF EXISTS produits CASCADE;
CREATE TABLE produits (
    produit_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nom TEXT,
    categorie TEXT,
    prix_achat NUMERIC(10,2),
    prix_vente NUMERIC(10,2),
    quantite INTEGER,
    stock_precedent INTEGER DEFAULT 0,
    ajustement INTEGER DEFAULT 0,
    stock_actuel INTEGER DEFAULT 0,
    dimension TEXT,
    code_barre TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    marge NUMERIC(10,2) NOT NULL,
    recuperation INTEGER DEFAULT 0
);

-- ventes
DROP TABLE IF EXISTS ventes CASCADE;
CREATE TABLE ventes (
    vente_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id INTEGER,
    client_nom TEXT,
    client_prenom TEXT,
    groupe TEXT,
    user_id INTEGER,
    username TEXT,
    date_vente TIMESTAMP,
    total NUMERIC(10,2),
    status TEXT NOT NULL,
    payment_method TEXT NOT NULL
);

-- vente_items
DROP TABLE IF EXISTS vente_items CASCADE;
CREATE TABLE vente_items (
    item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vente_id INTEGER,
    produit_id INTEGER,
    nom TEXT NOT NULL,
    quantite INTEGER,
    prix_achat NUMERIC(10,2),
    prix_vente NUMERIC(10,2) NOT NULL,
    subtotal NUMERIC(10,2) NOT NULL DEFAULT 0.00
);

-- commandes_sur_commande
DROP TABLE IF EXISTS commandes_sur_commande CASCADE;
CREATE TABLE commandes_sur_commande (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vente_id INTEGER NOT NULL,
    produit_id INTEGER,
    nom TEXT NOT NULL,
    quantite INTEGER NOT NULL,
    prix_vente NUMERIC(10,2) NOT NULL,
    total NUMERIC(12,2),
    date_commande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut TEXT CHECK(statut IN ('En attente','Livré','Annulé')) DEFAULT 'En attente',
    custom BOOLEAN DEFAULT TRUE,
    vente_item_id INTEGER,
    client_id INTEGER,
    client_nom TEXT,
    client_prenom TEXT
);

-- historique_stock
DROP TABLE IF EXISTS historique_stock CASCADE;
CREATE TABLE historique_stock (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    produit_id INTEGER NOT NULL,
    type_mouvement TEXT CHECK(type_mouvement IN ('VENTE','ANNULATION_VENTE','AJOUT','SUPPRESSION','AJUSTEMENT')) NOT NULL,
    quantite INTEGER NOT NULL,
    stock_avant INTEGER,
    stock_apres INTEGER,
    date_mouvement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    vente_id INTEGER,
    user_id INTEGER,
    commentaire TEXT
);

-- historique_vente
DROP TABLE IF EXISTS historique_vente CASCADE;
CREATE TABLE historique_vente (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vente_id INTEGER NOT NULL,
    ancien_status TEXT NOT NULL,
    nouveau_status TEXT NOT NULL,
    date_mouvement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER,
    commentaire TEXT
);

-- otp_verification
DROP TABLE IF EXISTS otp_verification CASCADE;
CREATE TABLE otp_verification (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- actions
DROP TABLE IF EXISTS actions CASCADE;
CREATE TABLE actions (
    action_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL,
    action_type TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- TRIGGERS: commandes_sur_commande BEFORE INSERT/UPDATE -> total = quantite*prix_vente
-- =========================
CREATE OR REPLACE FUNCTION trg_commandes_total()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.total := NEW.quantite * NEW.prix_vente;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS commandes_total_before_insert ON commandes_sur_commande;
CREATE TRIGGER commandes_total_before_insert
BEFORE INSERT ON commandes_sur_commande
FOR EACH ROW EXECUTE FUNCTION trg_commandes_total();

DROP TRIGGER IF EXISTS commandes_total_before_update ON commandes_sur_commande;
CREATE TRIGGER commandes_total_before_update
BEFORE UPDATE ON commandes_sur_commande
FOR EACH ROW EXECUTE FUNCTION trg_commandes_total();

-- =========================
-- TRIGGER produits BEFORE UPDATE -> stock_precedent, ajustement, stock_actuel
-- =========================
CREATE OR REPLACE FUNCTION trg_produits_update()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.stock_precedent := OLD.quantite;
    NEW.ajustement := COALESCE(NEW.quantite,0) - COALESCE(OLD.quantite,0);
    NEW.stock_actuel := NEW.quantite;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS before_stock_update ON produits;
CREATE TRIGGER before_stock_update
BEFORE UPDATE ON produits
FOR EACH ROW EXECUTE FUNCTION trg_produits_update();

-- =========================
-- TRIGGER users BEFORE INSERT -> generate username unique
-- =========================
CREATE OR REPLACE FUNCTION trg_user_generate_username()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    base_username TEXT;
    unique_username TEXT;
    count_existing INTEGER;
BEGIN
    base_username :=
        UPPER(SUBSTRING(NEW.user_nom FROM 1 FOR 1)) || '.' ||
        UPPER(SUBSTRING(NEW.user_prenom FROM 1 FOR 1)) ||
        LOWER(SUBSTRING(NEW.user_prenom FROM 2));

    unique_username := base_username;

    SELECT COUNT(*) INTO count_existing FROM users WHERE username = unique_username;

    WHILE count_existing > 0 LOOP
        unique_username := base_username || (count_existing + 1);
        SELECT COUNT(*) INTO count_existing FROM users WHERE username = unique_username;
    END LOOP;

    NEW.username := unique_username;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS before_user_insert ON users;
CREATE TRIGGER before_user_insert
BEFORE INSERT ON users
FOR EACH ROW EXECUTE FUNCTION trg_user_generate_username();

-- =========================
-- FOREIGN KEYS (added after all tables created to avoid dependency order issues)
-- =========================
ALTER TABLE actions ADD CONSTRAINT fk_actions_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE;
ALTER TABLE commandes_sur_commande ADD CONSTRAINT fk_commandes_client FOREIGN KEY (client_id) REFERENCES clients(client_id);
ALTER TABLE historique_stock ADD CONSTRAINT fk_hs_produit FOREIGN KEY (produit_id) REFERENCES produits(produit_id) ON DELETE CASCADE;
ALTER TABLE historique_stock ADD CONSTRAINT fk_hs_vente FOREIGN KEY (vente_id) REFERENCES ventes(vente_id) ON DELETE SET NULL;
ALTER TABLE historique_vente ADD CONSTRAINT fk_hv_vente FOREIGN KEY (vente_id) REFERENCES ventes(vente_id) ON DELETE CASCADE;
ALTER TABLE otp_verification ADD CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(user_id);
ALTER TABLE role_permissions ADD CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE;
ALTER TABLE users ADD CONSTRAINT fk_users_roles FOREIGN KEY (role_id) REFERENCES roles(role_id) ON UPDATE CASCADE;
ALTER TABLE ventes ADD CONSTRAINT fk_ventes_client FOREIGN KEY (client_id) REFERENCES clients(client_id);
ALTER TABLE vente_items ADD CONSTRAINT fk_vi_produit FOREIGN KEY (produit_id) REFERENCES produits(produit_id);
ALTER TABLE vente_items ADD CONSTRAINT fk_vi_vente FOREIGN KEY (vente_id) REFERENCES ventes(vente_id);

-- =========================
-- INDEXES
-- =========================
CREATE INDEX IF NOT EXISTS idx_actions_user_id ON actions(user_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_clients_telephone ON clients(telephone);
CREATE INDEX IF NOT EXISTS idx_commandes_client ON commandes_sur_commande(client_id);
CREATE INDEX IF NOT EXISTS idx_hs_vente_id ON historique_stock(vente_id);
CREATE INDEX IF NOT EXISTS idx_hs_produit_id ON historique_stock(produit_id);
CREATE INDEX IF NOT EXISTS idx_hs_date ON historique_stock(date_mouvement);
CREATE INDEX IF NOT EXISTS idx_hv_vente ON historique_vente(vente_id);
CREATE INDEX IF NOT EXISTS idx_hv_date ON historique_vente(date_mouvement);
CREATE INDEX IF NOT EXISTS idx_otp_user_id ON otp_verification(user_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_roles_nom_role ON roles(nom_role);
CREATE UNIQUE INDEX IF NOT EXISTS idx_role_permissions_unique ON role_permissions(role_id, permission);
CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id);
CREATE INDEX IF NOT EXISTS idx_ventes_client ON ventes(client_id);
CREATE INDEX IF NOT EXISTS idx_ventes_nom ON ventes(client_nom);
CREATE INDEX IF NOT EXISTS idx_ventes_prenom ON ventes(client_prenom);
CREATE INDEX IF NOT EXISTS idx_vi_produit ON vente_items(produit_id);
CREATE INDEX IF NOT EXISTS idx_vi_vente ON vente_items(vente_id);

-- =========================
-- INSERT DATA: roles, role_permissions, users (only users as requested)
-- Use OVERRIDING SYSTEM VALUE to keep original IDs if desired
-- =========================

-- roles
INSERT INTO roles(role_id, nom_role, description, date_creation)
OVERRIDING SYSTEM VALUE
VALUES
(1, 'administrateur', 'Administrateur du système — accès complet', '2025-11-10 14:37:39'),
(2, 'vendeur', 'Peut créer ventes, ajouter clients et lister les ventes et clients', '2025-11-10 14:37:39'),
(3, 'caissier', 'Enregistre les paiements et voit les rapports', '2025-11-10 14:37:39'),
(4, 'superviseur', 'Gère les produits, les stocks, actions sur les ventes et voit les rapport ', '2025-11-10 14:37:39');

-- role_permissions
INSERT INTO role_permissions(id, role_id, permission)
OVERRIDING SYSTEM VALUE
VALUES
(4, 1, 'creer_clients'),
(3, 1, 'creer_ventes'),
(2, 1, 'gerer_stock'),
(1, 1, 'gerer_utilisateurs'),
(5, 1, 'voir_rapports'),
(7, 2, 'creer_client'),
(6, 2, 'creer_vente'),
(12, 3, 'lister_vente'),
(11, 3, 'modifier_vente'),
(13, 3, 'voir_rapports'),
(9, 4, 'modifier_stock'),
(10, 4, 'voir_rapports'),
(8, 4, 'voir_stock');

-- users (only these INSERTS as requested)
INSERT INTO users(
    user_id, username, email, password, civilite,
    user_nom, user_prenom, created_at, status,
    status_user_account, photo, role_id, last_activity
)
OVERRIDING SYSTEM VALUE
VALUES
(1, 'P.Peterson', 'pierre.peterson757@gmail.com',
 '$2b$12$11ZICb6gX4g3WesHNnynDOXZ2SG6LReZUis3C0bt.LfvBljZz3Tku',
 'M.', 'Pierre', 'Peterson', '2025-11-04 10:13:31',
 'Online', 'actif', '1762270704_1761854468_1761853799_Peterson_profil.jpg', 1,
 '2025-11-19 10:31:34'),

(6, 'H.Gena', 'gena.heraty@nph.org',
 '$2y$10$h8dBgddWkp0O1SSUMljhSOFkc5fAEZzq5vWYn.D/jSnv7xp67dw9y',
 'M.', 'Heraty', 'Gena', '2025-11-15 03:37:50',
 'Disconnected', 'actif', '1763195870_IMG_9603.JPG', 1, NULL),

(7, 'P.Castro', 'plaisir.castro@gmail.com',
 '$2y$10$.YGNjP/KRA9fsDeX.1x0..uEUkNA6gxaWpaOE8OcxhaSbKNq0r5d6',
 'M.', 'Plaisir', 'Castro', '2025-11-15 08:47:14',
 'Disconnected', 'actif', '', 4, NULL),

(8, 'A.Marie marthe', 'antoine2025@gmail.com',
 '$2y$10$LWYL8m268j7DWGY01.y27O4iiC1QqSkP88WTxHNhJybi2Wbq7coGO',
 'Mme.', 'Antoine', 'Marie Marthe', '2025-11-15 09:40:20',
 'Disconnected', 'actif', '', 3, NULL),

(9, 'M.Fabiola', 'fabiola.moise@nph.org',
 '$2y$10$kspjEJ1UYMXY/2L1VLf2Q.6D2Fg6hi4jzmqi8aSJfp93DTsiE6ng6',
 'Mme.', 'Moise', 'Fabiola', '2025-11-17 08:53:35',
 'Disconnected', 'actif', '', 2, NULL);

-- =========================
-- Reset identity sequences to max values to avoid next-id conflicts
-- =========================
-- For identity columns, use pg_get_serial_sequence to find underlying sequence
SELECT setval(pg_get_serial_sequence('roles','role_id'), COALESCE((SELECT MAX(role_id) FROM roles), 1));
SELECT setval(pg_get_serial_sequence('role_permissions','id'), COALESCE((SELECT MAX(id) FROM role_permissions), 1));
SELECT setval(pg_get_serial_sequence('users','user_id'), COALESCE((SELECT MAX(user_id) FROM users), 1));
SELECT setval(pg_get_serial_sequence('clients','client_id'), COALESCE((SELECT MAX(client_id) FROM clients), 1));
SELECT setval(pg_get_serial_sequence('produits','produit_id'), COALESCE((SELECT MAX(produit_id) FROM produits), 1));
SELECT setval(pg_get_serial_sequence('ventes','vente_id'), COALESCE((SELECT MAX(vente_id) FROM ventes), 1));
SELECT setval(pg_get_serial_sequence('vente_items','item_id'), COALESCE((SELECT MAX(item_id) FROM vente_items), 1));
SELECT setval(pg_get_serial_sequence('commandes_sur_commande','id'), COALESCE((SELECT MAX(id) FROM commandes_sur_commande), 1));
SELECT setval(pg_get_serial_sequence('otp_verification','id'), COALESCE((SELECT MAX(id) FROM otp_verification), 1));
SELECT setval(pg_get_serial_sequence('historique_stock','id'), COALESCE((SELECT MAX(id) FROM historique_stock), 1));
SELECT setval(pg_get_serial_sequence('historique_vente','id'), COALESCE((SELECT MAX(id) FROM historique_vente), 1));
SELECT setval(pg_get_serial_sequence('actions','action_id'), COALESCE((SELECT MAX(action_id) FROM actions), 1));

-- =========================
-- Done. If you want more INSERT blocks (clients/produits/ventes/vente_items/commandes...) I can append them in chunks.
-- =========================
