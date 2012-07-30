
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
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	customer_id INTEGER NOT NULL,
	product VARCHAR(25) NOT NULL,
	FOREIGN KEY (customer_id) REFERENCES customers (id)
);

INSERT INTO orders (customer_id, product) VALUES (1, "Kop koffie");
INSERT INTO orders (customer_id, product) VALUES (2, "Walter PPK 9mm");
INSERT INTO orders (customer_id, product) VALUES (2, "Spycam");

CREATE TABLE groups (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	title VARCHAR(50)
);

INSERT INTO groups (title) VALUES ("Hacker");
INSERT INTO groups (title) VALUES ("Gambler");
INSERT INTO groups (title) VALUES ("Evil");

CREATE TABLE memberships (
	customer_id INTEGER NOT NULL,
	group_id INTEGER NOT NULL,
	PRIMARY KEY(customer_id, group_id),
	FOREIGN KEY (customer_id) REFERENCES customers (id),
	FOREIGN KEY (group_id) REFERENCES groups (id)
);

-- Bob is a Hacker
-- Bond is a Hacker and a Gambler
INSERT INTO memberships (customer_id, group_id) VALUES (1, 1);
INSERT INTO memberships (customer_id, group_id) VALUES (2, 1);
INSERT INTO memberships (customer_id, group_id) VALUES (2, 2);