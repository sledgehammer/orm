
-- Test database voor DAO unittests

-- Tabel met een auto_increment ID
CREATE TABLE customers (
	id INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
	name VARCHAR(25) NOT NULL DEFAULT "John Doe",
	occupation TEXT NOT NULL
) ENGINE = InnoDB;

INSERT INTO customers (name, occupation) VALUES
	("Bob Fanger", "Software ontwikkelaar"),
	("James Bond", "Spion");

CREATE TABLE orders (
	id INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
	customer_id INT NOT NULL,
	product VARCHAR(25) NOT NULL,
--	KEY customer_id (customer_id),
	CONSTRAINT belongs_to_customer FOREIGN KEY (customer_id) REFERENCES customers (id)

) ENGINE = InnoDB;

INSERT INTO orders (customer_id, product) VALUES
	(1, "Kop koffie"),
	(2, "Walter PPK 9mm"),
	(2, "Spycam");

CREATE TABLE groups (
	id INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT,
	title VARCHAR(50)
) ENGINE = InnoDB;

INSERT INTO groups (title) VALUES
	("Hacker"),
	("Gambler"),
	("Evil");

CREATE TABLE memberships (
	customer_id INTEGER NOT NULL,
	group_id INTEGER NOT NULL,
	PRIMARY KEY(customer_id, group_id),
	FOREIGN KEY (customer_id) REFERENCES customers (id),
	FOREIGN KEY (group_id) REFERENCES groups (id)
) ENGINE = InnoDB;

-- Bob is a Hacker
-- Bond is a Hacker and a Gambler
INSERT INTO memberships (customer_id, group_id) VALUES
	(1, 1),
	(2, 1),
	(2, 2);