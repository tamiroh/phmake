#ifndef PHMAKE_MODULE_API_H
#define PHMAKE_MODULE_API_H

typedef struct { const char *filenm; unsigned long lineno; } gmk_floc;
typedef char *(*gmk_func)(const char *, unsigned int, char **);

char *gmk_alloc(unsigned int size);
void gmk_free(void *memory);
char *gmk_expand(const char *expression);
void gmk_eval(const char *text, const gmk_floc *location);
void gmk_add_function(const char *name, gmk_func function,
                      unsigned int minimum, unsigned int maximum, unsigned int flags);

#endif
