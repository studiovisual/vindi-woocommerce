<?php

class VindiValidators {

  /**
   * Validates CPF number
   * 
   * @param mixed $value The CPF number
   *
   * @since 1.5.1
   *
   * @return bool True if the number is valid, false otherwise
   */
  public static function isValidCPF(mixed $value): bool {
    if(!is_string($value))
      return false;
  
    $value = preg_replace('/[^\d]+/', '', $value);
  
    if(strlen($value) !== 11 || preg_match('/(\d)\1{10}/', $value))
      return false;
  
    $values = array_map('intval', str_split($value));
    $rest = function($count) use ($values) {
      $slice = array_slice($values, 0, $count-12);
      $sum = 0;
      $index = 0;
      foreach ($slice as $el) {
          $sum += $el * ($count - $index);
          $index++;
      }
      return ($sum * 10) % 11 % 10;
    };
  
    return $rest(10) === $values[9] && $rest(11) === $values[10];
  }

  /**
   * Validates CNPJ number
   * 
   * @param mixed $value The CNPJ number
   *
   * @since 1.5.1
   *
   * @return bool True if the number is valid, false otherwise
   */
  public static function isValidCNPJ(mixed $value): bool {
    if(empty($value)) 
      return false;
  
    // Aceita receber o valor como string, número ou array com todos os dígitos
    $isString = is_string($value);
    $validTypes = $isString || is_int($value) || is_array($value);
  
    // Elimina valor em formato inválido
    if(!$validTypes) 
      return false;
  
    // Filtro inicial para entradas do tipo string
    if($isString) {
      // Limita ao máximo de 18 caracteres, para CNPJ formatado
      if(strlen($value) > 18) 
        return false;
  
      // Teste Regex para veificar se é uma string apenas dígitos válida
      $digitsOnly = preg_match('/^\d{14}$/', $value);
      // Teste Regex para verificar se é uma string formatada válida
      $validFormat = preg_match('/^\d{2}.\d{3}.\d{3}\/\d{4}-\d{2}$/', $value);
  
      // Se o formato é válido, usa um truque para seguir o fluxo da validação
      if($digitsOnly || $validFormat) 
        true;
      // Se não, retorna inválido
      else 
        return false;
    }
  
    // Guarda um array com todos os dígitos do valor
    $match = preg_match_all('/\d/', strval($value), $matches);
    $numbers = is_array($matches[0]) ? array_map('intval', $matches[0]) : [];
  
    // Valida a quantidade de dígitos
    if(count($numbers) !== 14) 
      return false;
  
    // Elimina inválidos com todos os dígitos iguais
    $items = array_unique($numbers);
    if(count($items) === 1) 
      return false;
  
    // Cálculo validador
    $calc = function($x) use ($numbers) {
      $slice = array_slice($numbers, 0, $x);
      $factor = $x - 7;
      $sum = 0;
  
      for($i = $x; $i >= 1; $i--) {
        $n = $slice[$x - $i];
        $sum += $n * $factor--;
        if ($factor < 2) $factor = 9;
      }
  
      $result = 11 - ($sum % 11);
  
      return $result > 9 ? 0 : $result;
    };
  
    // Separa os 2 últimos dígitos de verificadores
    $digits = array_slice($numbers, 12);
  
    // Valida 1o. dígito verificador
    $digit0 = $calc(12);

    if($digit0 !== $digits[0]) 
      return false;
  
    // Valida 2o. dígito verificador
    $digit1 = $calc(13);

    return $digit1 === $digits[1];
  }

  public static function isValidPhoneDDD($value) {
    $digits = preg_replace('/[^\d]/', '', $value);
    $areaCodes = [
      11, 12, 13, 14, 15, 16, 17, 18, 19, 21,
      22, 24, 27, 28, 31, 32, 33, 34, 35, 37,
      38, 41, 42, 43, 44, 45, 46, 47, 48, 49,
      51, 53, 54, 55, 61, 62, 63, 64, 65, 66,
      67, 68, 69, 71, 73, 74, 75, 77, 79, 81,
      82, 83, 84, 85, 86, 87, 88, 89, 91, 92,
      93, 94, 95, 96, 97, 98, 99,
    ];
    
    return in_array((int)substr($digits, 0, 2), $areaCodes);
  }
  
  public static function isValidPhoneLength($value) {
    $digits = preg_replace('/[^\d]/', '', $value);
    
    return strlen($digits) >= 10 && strlen($digits) <= 11;
  }
  
  public static function isValidPhoneFirstNumber($value) {
    $digits = preg_replace('/[^\d]/', '', $value);
    
    return strlen($digits) === 11 ? $digits[2] === '9' : true;
  }
  
  public static function isValidPhone(mixed $value): bool {
    return self::isValidPhoneLength($value) && self::isValidPhoneFirstNumber($value) && self::isValidPhoneDDD($value);
  }
  
}
