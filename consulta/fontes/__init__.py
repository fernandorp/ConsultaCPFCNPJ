from consulta.fontes.base import (
    CaptchaRequerido,
    ErroFonte,
    Fonte,
    Processo,
)
from consulta.fontes.tjsp_esaj import TJSP1Grau, TJSP2Grau

FONTES: dict[str, type[Fonte]] = {
    TJSP1Grau.codigo: TJSP1Grau,
    TJSP2Grau.codigo: TJSP2Grau,
}

__all__ = ["FONTES", "CaptchaRequerido", "ErroFonte", "Fonte", "Processo", "TJSP1Grau", "TJSP2Grau"]
