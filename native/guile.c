#include <libguile.h>
#include <stdlib.h>

#include "module-api.h"

static int initialized;
static SCM convert;

typedef struct {
    const char *expression;
    char *result;
    int failed;
} evaluation;

static char *make_string(SCM value)
{
    return scm_to_utf8_string(scm_call_1(convert, value));
}

static SCM expand(SCM value)
{
    char *expression = make_string(value);
    char *expanded = gmk_expand(expression);
    SCM result = scm_from_utf8_string(expanded);
    free(expression);
    free(expanded);
    return result;
}

static SCM evaluate(SCM value)
{
    char *expression = make_string(value);
    gmk_eval(expression, NULL);
    free(expression);
    return SCM_UNSPECIFIED;
}

static SCM run(void *data)
{
    evaluation *state = data;
    state->result = make_string(scm_c_eval_string(state->expression));
    scm_c_eval_string("(force-output) (force-output (current-error-port))");
    return SCM_UNSPECIFIED;
}

static SCM fail(void *data, SCM key, SCM arguments)
{
    evaluation *state = data;
    state->failed = 1;
    state->result = scm_to_utf8_string(scm_simple_format(
        SCM_BOOL_F, scm_from_utf8_string("~S: ~S"), scm_list_2(key, arguments)));
    return SCM_UNSPECIFIED;
}

/* Scheme data is converted to make's whitespace-separated word representation. */
char *phmake_guile(const char *expression, int *failed)
{
    if (!initialized) {
        scm_init_guile();
        convert = scm_c_eval_string(
            "(letrec ((convert (lambda (value)"
            " (cond ((or (null? value) (eq? value #f) (unspecified? value)) \"\")"
            "       ((string? value) value)"
            "       ((char? value) (string value))"
            "       ((symbol? value) (symbol->string value))"
            "       ((number? value) (number->string value))"
            "       ((eq? value #t) \"#t\")"
            "       ((pair? value)"
            "        (let ((head (convert (car value))) (tail (convert (cdr value))))"
            "          (cond ((string-null? head) tail)"
            "                ((string-null? tail) head)"
            "                (else (string-append head \" \" tail)))))"
            "       (else \"\"))))) convert)");
        scm_gc_protect_object(convert);
        scm_c_define_gsubr("gmk-expand", 1, 0, 0, expand);
        scm_c_define_gsubr("gmk-eval", 1, 0, 0, evaluate);
        scm_c_eval_string(
            "(define (gmk-var name)"
            " (gmk-expand (string-append \"$(\""
            "  (if (symbol? name) (symbol->string name) name) \")\")))");
        initialized = 1;
    }
    evaluation state = {expression, NULL, 0};
    scm_c_catch(SCM_BOOL_T, run, &state, fail, &state, NULL, NULL);
    *failed = state.failed;
    return state.result;
}
