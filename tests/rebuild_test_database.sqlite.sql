
-- Test database voor DAO unittests

-- Tabel met een auto_increment ID
CREATE TABLE customers (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(25) NOT NULL DEFAULT "John Doe",
	occupation TEXT NOT NULL
);

INSERT INTO customers (name, occupation) VALUES ("Bob Fanger", "Software ontwikkelaar");
INSERT INTO customers (name, occupation) VALUES ("James Bond", "Spion");

CREATE TABLE orders (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT ,
	customer_id INTEGER NOT NULL,
	product VARCHAR(25) NOT NULL,
	FOREIGN KEY (customer_id) REFERENCES customers (id)
);

INSERT INTO orders (customer_id, product) VALUES (1, "Kop koffie");
INSERT INTO orders (customer_id, product) VALUES (2, "Walter PPK 9mm");
INSERT INTO orders (customer_id, product) VALUES (2, "Spycam");