<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 일괄 업로드 행 단위 검증 실패(내부 제어 흐름용). 메시지는 사용자에게 노출되는 사유.
 */
final class RowException extends RuntimeException
{
}
