-- Script SQL pour initialiser la base de données 'boutique'
CREATE DATABASE IF NOT EXISTS boutique_stg;
USE boutique_stg;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','vendeur') DEFAULT 'vendeur',
  fullname VARCHAR(100)
);

CREATE TABLE produits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  categorie VARCHAR(100),
  prix DECIMAL(10,2) NOT NULL,
  quantite INT DEFAULT 0,
  code_barre VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150),
  telephone VARCHAR(50),
  email VARCHAR(100),
  adresse TEXT
);

CREATE TABLE ventes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  total DECIMAL(12,2) NOT NULL,
  date_vente TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  user_id INT,
  payment_method VARCHAR(50),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE vente_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vente_id INT NOT NULL,
  produit_id INT NOT NULL,
  quantite INT NOT NULL,
  prix_unitaire DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE,
  FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE RESTRICT
);
