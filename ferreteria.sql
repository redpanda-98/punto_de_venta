-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 09-10-2026 a las 01:27:44
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `ferreteria`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empleados`
--

CREATE TABLE `empleados` (
  `id_empleado` int(11) NOT NULL,
  `nombre` varchar(75) DEFAULT NULL,
  `telefono` varchar(10) DEFAULT NULL,
  `ventas_mensuales` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Volcado de datos para la tabla `empleados`
--

INSERT INTO `empleados` (`id_empleado`, `nombre`, `telefono`, `ventas_mensuales`) VALUES
(1, 'Victor Vicente', '1234567890', 18552.40),
(2, 'Maria Sandoval', '0987654321', 19992.85),
(3, 'Jared Rodriguez', '6789054321', 1199.80);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id_producto` int(11) NOT NULL,
  `nombre` varchar(75) DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT NULL,
  `cantidad` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id_producto`, `nombre`, `precio`, `cantidad`) VALUES
(1, 'Tornillos', 2.50, 900),
(2, 'Desarmadores', 59.99, 280),
(3, 'Tubos de PVC', 249.99, 70),
(4, 'Candados', 125.50, 489),
(5, 'Taladros', 1599.30, 86);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas`
--

CREATE TABLE `ventas` (
  `id_venta` int(11) NOT NULL,
  `folio_venta` char(32) DEFAULT NULL,
  `id_empleado` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `fecha_compra` date DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Volcado de datos para la tabla `ventas`
--

INSERT INTO `ventas` (`id_venta`, `id_empleado`, `id_producto`, `cantidad`, `total`, `fecha_compra`) VALUES
(1, 1, 4, 10, 1255.00, '2026-10-08'),
(2, 2, 1, 100, 250.00, '2026-10-08'),
(3, 3, 2, 20, 1199.80, '2026-10-08'),
(4, 1, 5, 3, 4797.90, '2026-10-08'),
(5, 1, 3, 50, 12499.50, '2026-10-08'),
(8, 2, 3, 15, 3749.85, '2026-10-08'),
(11, 2, 5, 10, 15993.00, '2026-10-08');

--
-- Disparadores `ventas`
--
DELIMITER $$
CREATE TRIGGER `actualizar_ventas_mensuales` AFTER INSERT ON `ventas` FOR EACH ROW BEGIN
    UPDATE empleados
    SET ventas_mensuales = (
        SELECT COALESCE(SUM(v.total), 0)
        FROM ventas v
        WHERE v.id_empleado = NEW.id_empleado
          AND v.fecha_compra >= DATE_FORMAT(
              NEW.fecha_compra, '%Y-%m-01'
          )
          AND v.fecha_compra < DATE_ADD(
              DATE_FORMAT(NEW.fecha_compra, '%Y-%m-01'),
              INTERVAL 1 MONTH
          )
    )
    WHERE id_empleado = NEW.id_empleado;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `actualizar_ventas_mensuales_delete` AFTER DELETE ON `ventas` FOR EACH ROW BEGIN
    UPDATE empleados
    SET ventas_mensuales = (
        SELECT COALESCE(SUM(v.total), 0)
        FROM ventas v
        WHERE v.id_empleado = OLD.id_empleado
          AND v.fecha_compra >= DATE_FORMAT(
              OLD.fecha_compra, '%Y-%m-01'
          )
          AND v.fecha_compra < DATE_ADD(
              DATE_FORMAT(OLD.fecha_compra, '%Y-%m-01'),
              INTERVAL 1 MONTH
          )
    )
    WHERE id_empleado = OLD.id_empleado;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `calcular_total` BEFORE INSERT ON `ventas` FOR EACH ROW BEGIN
    DECLARE precio_producto DECIMAL(10,2);

    SELECT precio
    INTO precio_producto
    FROM productos
    WHERE id_producto = NEW.id_producto;

    SET NEW.total = NEW.cantidad * precio_producto;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reducir_stock` AFTER INSERT ON `ventas` FOR EACH ROW BEGIN

    UPDATE productos
    SET cantidad = cantidad - NEW.cantidad
    WHERE id_producto = NEW.id_producto;

END
$$
DELIMITER ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `empleados`
--
ALTER TABLE `empleados`
  ADD PRIMARY KEY (`id_empleado`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id_producto`);

--
-- Indices de la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id_venta`),
  ADD KEY `id_empleado` (`id_empleado`),
  ADD KEY `id_producto` (`id_producto`),
  ADD KEY `idx_ventas_folio_venta` (`folio_venta`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `empleados`
--
ALTER TABLE `empleados`
  MODIFY `id_empleado` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id_producto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id_venta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`),
  ADD CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
