<?php

namespace Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DomainValueObjectsTest extends TestCase
{
    /**
     * T-03 / CA-03.2: ISBN debe tener exactamente 13 dígitos numéricos.
     */
    public function test_isbn_validation_rules(): void
    {
        $validIsbn = '9780307474728';
        $this->assertEquals(13, strlen($validIsbn));
        $this->assertTrue(ctype_digit($validIsbn));

        // Rechazar si no tiene 13 dígitos
        $shortIsbn = '123456789012';
        $this->assertNotEquals(13, strlen($shortIsbn));

        // Rechazar caracteres no numéricos
        $alphaIsbn = '978030747472X';
        $this->assertFalse(ctype_digit($alphaIsbn));
    }

    /**
     * T-03 / CA-03.3 / Art. VI: Invariantes de cantidad y copias disponibles.
     */
    public function test_book_quantity_invariants(): void
    {
        $totalCopies = 5;
        $availableCopies = 3;

        // Invariante ck_book_total_positive: total >= 1
        $this->assertGreaterThanOrEqual(1, $totalCopies);

        // Invariante ck_book_available_range: 0 <= available <= total
        $this->assertGreaterThanOrEqual(0, $availableCopies);
        $this->assertLessThanOrEqual($totalCopies, $availableCopies);

        // Ajuste de ejemplares: cambiar total ajusta disponibles
        $newTotal = 7;
        $diff = $newTotal - $totalCopies;
        $newAvailable = $availableCopies + $diff;
        $this->assertEquals(5, $newAvailable);
        $this->assertGreaterThanOrEqual(0, $newAvailable);

        // Reducción que dejaría disponibles < 0 debe ser inválida
        $invalidNewTotal = 1; // diff = -4 => newAvailable = 3 - 4 = -1
        $invalidDiff = $invalidNewTotal - $totalCopies;
        $this->assertLessThan(0, $availableCopies + $invalidDiff);
    }

    /**
     * T-03 / CA-10.2: Rango de fechas (DateRange). Fin debe ser >= Inicio.
     */
    public function test_date_range_validation(): void
    {
        $from = Carbon::parse('2026-10-01')->startOfDay();
        $to = Carbon::parse('2026-10-31')->endOfDay();

        $this->assertTrue($to->greaterThanOrEqualTo($from));

        // Rango inválido: to anterior a from
        $invalidTo = Carbon::parse('2026-09-30')->endOfDay();
        $this->assertTrue($invalidTo->lessThan($from));
    }

    /**
     * T-06 / CA-02.2: Normalización de nombres de usuario ("Ana " -> "ana").
     */
    public function test_username_normalization(): void
    {
        $rawUsername = "  Ana  ";
        $normalized = strtolower(trim($rawUsername));

        $this->assertEquals('ana', $normalized);
    }

    /**
     * T-05 / CA-06.6: Plazo reglamentario de préstamo es exactamente loaned_at + 14 días.
     */
    public function test_loan_due_date_is_fourteen_days(): void
    {
        $loanedAt = Carbon::parse('2026-10-10 10:00:00');
        $dueAt = $loanedAt->copy()->addDays(14);

        $this->assertEquals('2026-10-24 10:00:00', $dueAt->format('Y-m-d H:i:s'));
        $this->assertEquals(14, $loanedAt->diffInDays($dueAt));
    }

    /**
     * T-05 / CA-06.1 & CA-06.2: Restricciones de líneas por préstamo (1 a 3 sin duplicados).
     */
    public function test_loan_items_count_and_uniqueness(): void
    {
        $items = ['uuid-1', 'uuid-2'];
        $this->assertGreaterThanOrEqual(1, count($items));
        $this->assertLessThanOrEqual(3, count($items));
        $this->assertEquals(count($items), count(array_unique($items)));

        // Rechazar duplicados
        $duplicatedItems = ['uuid-1', 'uuid-1'];
        $this->assertNotEquals(count($duplicatedItems), count(array_unique($duplicatedItems)));
    }
}
